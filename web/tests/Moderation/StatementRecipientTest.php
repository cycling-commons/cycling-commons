<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\Item;
use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\Entity\RouteSuggestion;
use App\Catalog\Entity\Submission;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\RouteSuggestionReason;
use App\Catalog\RouteSuggestionStatus;
use App\Catalog\SubmissionType;
use App\Contribution\RoutePhotoService;
use App\Entity\User;
use App\Media\Entity\ConsentRecord;
use App\Media\Entity\MediaUpload;
use App\Media\MediaConsent;
use App\Media\MediaStorage;
use App\Media\ProcessedPhoto;
use App\Messaging\Entity\UserMessage;
use App\Messaging\MessageService;
use App\Messaging\UserMessageKind;
use App\Moderation\ModerationService;
use App\Moderation\RouteModerationService;
use App\Moderation\StatementGround;
use App\Moderation\StatementOfReasons;
use App\Support\ContentReportService;
use App\Support\Entity\ContentReport;
use App\Support\ReportGround;
use App\Support\ReportStatus;
use App\Support\ReportTarget;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A statement of reasons says truthfully whether a report led to the
 * decision, from the reports on file rather than from a box the curator
 * ticks, and it is never addressed to the curator who decided.
 *
 * @see docs/specs/content-reports.md §7
 */
final class StatementRecipientTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * A report on the route was upheld, and its author was told "this
     * followed a report". The curator then retires the route on its desk: that
     * statement must not say "no report was involved".
     */
    public function testRetiringARouteAfterAnUpheldReportSaysItFollowedAReport(): void
    {
        $proposer = $this->user();
        $route = $this->route(ItemState::Verified, $proposer);
        $this->report(ReportTarget::Route, (string) $route->getId(), ReportStatus::Upheld);

        $this->routes()->retire((int) $route->getId(), $this->user(), 'The road is closed to bikes.');

        $statement = $this->statementOn($proposer, UserMessageKind::RouteRetired);
        self::assertTrue($statement->fromReport);
        self::assertSame('This followed a report from somebody who saw it.', $statement->lines($this->translator(), 'en', true)['source']);
    }

    public function testAReportStillWaitingCountsAndARejectedOneDoesNot(): void
    {
        $proposer = $this->user();
        $reported = $this->route(ItemState::Submitted, $proposer);
        $this->report(ReportTarget::Route, (string) $reported->getId(), ReportStatus::Open);
        $cleared = $this->route(ItemState::Verified, $proposer);
        $this->report(ReportTarget::Route, (string) $cleared->getId(), ReportStatus::Rejected);

        $this->routes()->reject((int) $reported->getId(), $this->user(), 'Same loop as route 12.');
        $this->routes()->retire((int) $cleared->getId(), $this->user(), 'The bridge is gone for good.');

        self::assertTrue($this->statementOn($proposer, UserMessageKind::RouteRejected)->fromReport, 'a report waiting on the desk led here');
        self::assertFalse($this->statementOn($proposer, UserMessageKind::RouteRetired)->fromReport, 'a report we found nothing in did not');
    }

    public function testTrashAsAbuseAfterAReportSaysSo(): void
    {
        $proposer = $this->user();
        $route = $this->route(ItemState::Submitted, $proposer);
        $this->report(ReportTarget::Route, (string) $route->getId(), ReportStatus::Upheld);

        $corrector = $this->user();
        $live = $this->route(ItemState::Verified, null);
        $correction = new RouteSuggestion((int) $live->getId(), (int) $corrector->getId(), RouteSuggestionReason::Other, 'Abusive text');
        $this->em->persist($correction);
        $this->em->flush();
        // The corrector's reply in the correction's thread was reported.
        $reply = static::getContainer()->get(MessageService::class)->sendRiderReply((int) $this->user()->getId(), (int) $corrector->getId(), 'correction', (int) $correction->getId(), 'correction', 'A threat.');
        self::assertNotNull($reply);
        $this->report(ReportTarget::Message, (string) $reply->getId(), ReportStatus::InProgress);

        $this->routes()->trashProposal((int) $route->getId(), $this->user(), StatementGround::Abuse, 'The name insults a named person.');
        $this->routes()->trashSuggestion((int) $correction->getId(), $this->user(), StatementGround::Abuse, 'Threatens the route\'s author.');

        self::assertTrue($this->statementOn($proposer, UserMessageKind::StatementOfReasons)->fromReport);
        self::assertTrue($this->statementOn($corrector, UserMessageKind::StatementOfReasons)->fromReport, 'a report on what they wrote in its thread');
    }

    public function testASubmissionWhoseNewPlaceWasReportedSaysSo(): void
    {
        $rider = $this->user();
        $trashed = $this->newPlace($rider);
        $this->report(ReportTarget::Item, (string) $trashed->getItemId(), ReportStatus::Open);
        $rejected = $this->newPlace($rider);
        $this->report(ReportTarget::Item, (string) $rejected->getItemId(), ReportStatus::Upheld);
        $plain = $this->newPlace($rider);

        $this->moderation()->trashSubmission((int) $trashed->getId(), $this->user(), StatementGround::Abuse, 'A slur in the name.');
        $this->moderation()->decide((int) $rejected->getId(), 'reject', $this->user(), 'Not a public tap.');
        $this->moderation()->decide((int) $plain->getId(), 'reject', $this->user(), 'Not a public tap either.');

        self::assertTrue($this->statementOn($rider, UserMessageKind::StatementOfReasons)->fromReport);
        $rejections = array_map(
            static fn (UserMessage $m): ?StatementOfReasons => StatementOfReasons::fromArray($m->getBodyParams()[StatementOfReasons::PARAM] ?? null),
            $this->em->getRepository(UserMessage::class)->findBy(['userId' => $rider->getId(), 'kind' => UserMessageKind::SubmissionRejected], ['id' => 'ASC']),
        );
        self::assertSame([true, false], array_map(static fn (?StatementOfReasons $s): ?bool => $s?->fromReport, $rejections));
    }

    /**
     * A curator who unticks photos on a correction of their own, or dismisses
     * it, has nobody to explain it to.
     */
    public function testACuratorDecidingOnTheirOwnPhotosIsSentNoStatement(): void
    {
        $curator = $this->user();
        $route = $this->route(ItemState::Verified, null);
        $kept = $this->upload($curator);
        $dropped = $this->upload($curator);
        $result = static::getContainer()->get(RoutePhotoService::class)
            ->submit($route, $curator, [$kept->getId()->toRfc4122(), $dropped->getId()->toRfc4122()], null, null);
        $suggestionId = (int) $result['suggestion']->getId();
        if ($result['applied']) {
            self::markTestSkipped('the correction applied at once; nothing was left to untick');
        }

        $this->routes()->resolveSuggestion($suggestionId, RouteSuggestionStatus::Done, $curator, [$dropped->getId()->toRfc4122()]);

        self::assertSame([], $this->statementsFor($curator), 'no statement to themselves');
    }

    public function testACuratorDecidingOnTheirOwnContributionIsSentNoStatement(): void
    {
        $curator = $this->user();
        $own = $this->route(ItemState::Submitted, $curator);
        $live = $this->route(ItemState::Verified, $curator);
        $trashed = $this->route(ItemState::Submitted, $curator);
        $sub = $this->newPlace($curator);
        $other = $this->newPlace($curator);

        $this->routes()->reject((int) $own->getId(), $curator, 'Same loop as route 12.');
        $this->routes()->retire((int) $live->getId(), $curator, 'The bridge is gone.');
        $this->routes()->trashProposal((int) $trashed->getId(), $curator, StatementGround::Abuse, 'Test text.');
        $this->moderation()->decide((int) $sub->getId(), 'reject', $curator, 'Not a public tap.');
        $this->moderation()->trashSubmission((int) $other->getId(), $curator, StatementGround::Abuse, 'Test text.');

        self::assertSame([], $this->statementsFor($curator));
    }

    public function testACuratorUpholdingAReportOnTheirOwnRouteIsSentNoStatement(): void
    {
        $curator = $this->user();
        $route = $this->route(ItemState::Verified, $curator);
        $report = new ContentReport(Uuid::v4(), ReportTarget::Route, (string) $route->getId(), ReportGround::Abuse, 'Reported in a test.', 'k-'.bin2hex(random_bytes(6)), new \DateTimeImmutable('-1 hour'));
        $report->decide(ReportStatus::Upheld, 'Upheld on my own route.', (int) $curator->getId(), new \DateTimeImmutable());
        $this->em->persist($report);
        $this->em->flush();

        static::getContainer()->get(ContentReportService::class)->tellAuthor($report, $curator, $route->getName());

        self::assertSame([], $this->statementsFor($curator));
        self::assertTrue($report->isAuthorTold(), 'nothing is left owed');
    }

    private function statementOn(User $rider, UserMessageKind $kind): StatementOfReasons
    {
        $message = $this->em->getRepository(UserMessage::class)->findOneBy(['userId' => $rider->getId(), 'kind' => $kind], ['id' => 'ASC']);
        self::assertInstanceOf(UserMessage::class, $message, $kind->value);
        $statement = StatementOfReasons::fromArray($message->getBodyParams()[StatementOfReasons::PARAM] ?? null);
        self::assertNotNull($statement, $kind->value);

        return $statement;
    }

    /** @return list<string> the references of every statement the rider holds */
    private function statementsFor(User $rider): array
    {
        $out = [];
        foreach ($this->em->getRepository(UserMessage::class)->findBy(['userId' => $rider->getId()]) as $message) {
            $statement = StatementOfReasons::fromArray($message->getBodyParams()[StatementOfReasons::PARAM] ?? null);
            if (null !== $statement) {
                $out[] = $statement->reference;
            }
        }

        return $out;
    }

    private function report(ReportTarget $target, string $id, ReportStatus $status): void
    {
        $report = new ContentReport(Uuid::v4(), $target, $id, ReportGround::Abuse, 'Reported in a test.', 'k-'.bin2hex(random_bytes(6)), new \DateTimeImmutable('-1 hour'));
        if (ReportStatus::InProgress === $status) {
            $report->takeUp();
        } elseif (ReportStatus::Open !== $status) {
            $report->decide($status, 'Decided in a test.', (int) $this->user()->getId(), new \DateTimeImmutable());
        }
        $this->em->persist($report);
        $this->em->flush();
    }

    private function newPlace(User $rider): Submission
    {
        $geom = '{"type":"Point","coordinates":[5.86,50.47]}';
        $item = (new Item())->setLetter('B')->setName('Kraan')->setGeom($geom)->setCountryCode('BE')
            ->setState(ItemState::Submitted)->setSource(ItemSource::User)->setSourceRef('user:stmt-'.bin2hex(random_bytes(8)))
            ->setAttributes(['type' => 'Drinking tap']);
        $this->em->persist($item);
        $this->em->flush();
        $sub = (new Submission())->setType(SubmissionType::NewItem)->setLetter('B')->setUserId((int) $rider->getId())
            ->setItemId($item->getId())->setTitle('Kraan')->setGeom($geom)->setCountryCode('BE')
            ->setChanges([])->setPayload([]);
        $this->em->persist($sub);
        $this->em->flush();

        return $sub;
    }

    private function route(ItemState $state, ?User $proposer): RecommendedRoute
    {
        $r = (new RecommendedRoute())->setName('Boucle des Motifs')
            ->setGeom('{"type":"LineString","coordinates":[[5.3,50.4],[5.3,50.5]]}')
            ->setState($state)->setSource(ItemSource::User)
            ->setSourceRef('user:route-stmt-'.bin2hex(random_bytes(8)))->setRegionId(null)
            ->setProposedBy($proposer?->getId());
        $this->em->persist($r);
        $this->em->flush();

        return $r;
    }

    private function upload(User $owner): MediaUpload
    {
        $consent = new ConsentRecord(Uuid::v4(), (int) $owner->getId(), MediaConsent::KIND, MediaConsent::VERSION, MediaConsent::hash('x'));
        $this->em->persist($consent);
        $upload = new MediaUpload(Uuid::v4(), (int) $owner->getId(), $consent->getId(), 'EU', 1200, 900, 4242, new \DateTimeImmutable('2026-05-03'), bucket: 'test-bucket-eu-01');
        $this->em->persist($upload);
        $this->em->flush();
        static::getContainer()->get(MediaStorage::class)->store('test-bucket-eu-01', $upload->getPathPrefix(), new ProcessedPhoto('O', 'L', 'S', 1200, 900));

        return $upload;
    }

    private function routes(): RouteModerationService
    {
        return static::getContainer()->get(RouteModerationService::class);
    }

    private function moderation(): ModerationService
    {
        return static::getContainer()->get(ModerationService::class);
    }

    private function translator(): TranslatorInterface
    {
        return static::getContainer()->get(TranslatorInterface::class);
    }

    private function user(): User
    {
        $u = (new User())->setEmail('stmt-'.uniqid('', true).'@statement.test');
        $u->setPassword('x');
        $this->em->persist($u);
        $this->em->flush();

        return $u;
    }
}
