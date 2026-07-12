<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\Entity\RouteSuggestion;
use App\Catalog\Entity\Submission;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\RouteSuggestionReason;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use App\Moderation\RetentionService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Retention phase 1 (M8): decided rows older than the cutoff are garbage —
 * both the raw sweep() (used by the command and the opportunistic hook) and
 * the throttling around it.
 */
final class RetentionTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $db;
    private RetentionService $retention;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->db = static::getContainer()->get(Connection::class);
        $this->retention = static::getContainer()->get(RetentionService::class);

        // Filesystem cache pools aren't rolled back by the DB transaction —
        // clear the throttle key so tests don't leak state into each other.
        static::getContainer()->get(CacheInterface::class)->delete('moderation_gc_last');
    }

    private function seedSubmission(SubmissionStatus $status, ?string $decidedAt): Submission
    {
        $sub = (new Submission())
            ->setType(SubmissionType::NewItem)->setLetter('D')->setUserId(7)
            ->setStatus($status)->setTitle('Retention fixture '.uniqid())
            ->setGeom('{"type":"Point","coordinates":[5.5,50.5]}')
            ->setCountryCode('BE')
            ->setChanges([])->setPayload([]);
        if (null !== $decidedAt) {
            $sub->setDecidedAt(new \DateTimeImmutable($decidedAt));
        }
        $this->em->persist($sub);
        $this->em->flush();

        return $sub;
    }

    private function seedRouteSuggestion(string $status, ?string $resolvedAt): RouteSuggestion
    {
        $route = (new RecommendedRoute())->setName('Retention route '.uniqid())
            ->setGeom('{"type":"LineString","coordinates":[[5.2,50.4],[5.3,50.5]]}')
            ->setDistanceM(5000)->setState(ItemState::Unverified)
            ->setSource(ItemSource::User)->setSourceRef('user:ret-'.uniqid());
        $this->em->persist($route);
        $this->em->flush();

        $suggestion = new RouteSuggestion($route->getId(), 9, RouteSuggestionReason::Other, null);
        $this->em->persist($suggestion);
        $this->em->flush();

        // resolve() always stamps "now" — backdate with a direct UPDATE
        // (honest per the brief: no setter exists for a custom resolved_at).
        $this->db->executeStatement(
            'UPDATE route_suggestion SET status = :status, resolved_at = :resolved_at, resolved_by = 1 WHERE id = :id',
            ['status' => $status, 'resolved_at' => $resolvedAt, 'id' => $suggestion->getId()],
        );

        return $suggestion;
    }

    public function testSweepDeletesOnlyDecidedRowsPastCutoff(): void
    {
        $oldRejected = $this->seedSubmission(SubmissionStatus::Rejected, '-4 months');
        $youngRejected = $this->seedSubmission(SubmissionStatus::Rejected, '-1 day');
        $oldPending = $this->seedSubmission(SubmissionStatus::Pending, null);

        $oldDismissed = $this->seedRouteSuggestion('dismissed', (new \DateTimeImmutable('-4 months'))->format('Y-m-d H:i:s'));
        $youngDismissed = $this->seedRouteSuggestion('dismissed', (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s'));
        $oldPendingSuggestion = $this->seedRouteSuggestion('pending', null);

        $counts = $this->retention->sweep();

        self::assertSame(1, $counts['submissions']);
        self::assertSame(1, $counts['corrections']);

        $this->em->clear();
        self::assertNull($this->em->find(Submission::class, $oldRejected->getId()));
        self::assertNotNull($this->em->find(Submission::class, $youngRejected->getId()));
        self::assertNotNull($this->em->find(Submission::class, $oldPending->getId()));
        self::assertNull($this->em->find(RouteSuggestion::class, $oldDismissed->getId()));
        self::assertNotNull($this->em->find(RouteSuggestion::class, $youngDismissed->getId()));
        self::assertNotNull($this->em->find(RouteSuggestion::class, $oldPendingSuggestion->getId()));
    }

    public function testSweepIsIdempotent(): void
    {
        $this->seedSubmission(SubmissionStatus::Rejected, '-4 months');
        $this->seedRouteSuggestion('dismissed', (new \DateTimeImmutable('-4 months'))->format('Y-m-d H:i:s'));

        $first = $this->retention->sweep();
        self::assertSame(1, $first['submissions']);
        self::assertSame(1, $first['corrections']);

        $second = $this->retention->sweep();
        self::assertSame(0, $second['submissions']);
        self::assertSame(0, $second['corrections']);
    }

    public function testSweepOpportunisticallyRunsAtMostOncePerTtl(): void
    {
        $this->seedSubmission(SubmissionStatus::Rejected, '-4 months');

        $this->retention->sweepOpportunistically();
        // First submission gone already — a second call within the TTL must
        // be a no-op, i.e. a fresh old row seeded after it stays undeleted.
        $stillOld = $this->seedSubmission(SubmissionStatus::Rejected, '-4 months');
        $this->retention->sweepOpportunistically();

        $this->em->clear();
        self::assertNotNull($this->em->find(Submission::class, $stillOld->getId()), 'throttled — second call within the TTL must not sweep again');
    }
}
