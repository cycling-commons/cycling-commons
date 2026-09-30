<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\Submission;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The search box on the submissions desk and on History
 * (docs/specs/moderation-and-contribution.md §5.2): a submitter is found by
 * name only when the card shows their name, and by pseudonym always.
 */
final class QueueSearchTest extends WebTestCase
{
    private int $seq = 0;

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @param list<string> $roles */
    private function user(string $name, array $roles = [], bool $public = false): User
    {
        $user = (new User())
            ->setEmail(sprintf('qsearch-%d-%s@example.test', ++$this->seq, uniqid()))
            ->setDisplayName($name)->setPassword('x')->setRoles($roles)
            ->setEmailVerified(true)->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setPublicProfile($public);
        if ([] !== $roles) {
            // Elevated roles must be TOTP-enrolled or TwoFactorSetupEnforcer redirects.
            $user->setTotpSecret('JBSWY3DPEHPK3PXP');
            $user->setTwoFaEnabled(true);
        }
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function curator(KernelBrowser $client, string $name = 'Desk Colleague'): User
    {
        $curator = $this->user($name, ['ROLE_CURATOR']);
        $client->loginUser($curator);

        return $curator;
    }

    private function submission(User $by, string $title, SubmissionStatus $status = SubmissionStatus::Pending, ?int $regionId = null, ?User $decidedBy = null): Submission
    {
        $sub = (new Submission())
            ->setType(SubmissionType::NewItem)->setLetter('D')->setUserId((int) $by->getId())
            ->setStatus($status)->setTitle($title)
            ->setGeom('{"type":"Point","coordinates":[5.5,50.5]}')->setCountryCode('BE')->setRegionId($regionId)
            ->setChanges([])->setPayload([]);
        $this->em()->persist($sub);
        $this->em()->flush();
        if (null !== $decidedBy) {
            $this->em()->getConnection()->executeStatement(
                'UPDATE submission SET decided_by = :by, decided_at = now() WHERE id = :id',
                ['by' => $decidedBy->getId(), 'id' => $sub->getId()],
            );
        }

        return $sub;
    }

    /** @return list<string> the titles of the cards the page lists */
    private function titles(KernelBrowser $client, string $path, string $q): array
    {
        $crawler = $client->request('GET', $path.'?q='.rawurlencode($q));
        self::assertResponseIsSuccessful();

        // The title without the kind label History prints beside it.
        return $crawler->filter('.q-list .q-item .q-title')->each(static function ($n): string {
            $kind = $n->filter('.q-kind');

            return trim(str_replace($kind->count() > 0 ? $kind->text() : "\0", '', $n->text()));
        });
    }

    // ── BUG-15: a private rider's name is not a search key ──────────────────

    public function testAPrivateSubmitterIsFoundByPseudonymNotByName(): void
    {
        $client = static::createClient();
        $this->curator($client);
        $private = $this->user('Hidden Hendrika');
        $this->submission($private, 'Tap behind the chapel');
        $this->submission($private, 'Settled tap behind the chapel', SubmissionStatus::Approved);

        foreach (['/moderate/submissions', '/moderate/submissions/history'] as $path) {
            self::assertSame([], $this->titles($client, $path, 'Hendrika'), $path.': the name of a private rider finds nothing');
            $byPseudonym = $this->titles($client, $path, 'rider#'.$private->getPseudonym());
            self::assertCount(1, $byPseudonym, $path.': the pseudonym the card shows finds it');
        }
    }

    public function testAPublicSubmitterIsFoundByName(): void
    {
        $client = static::createClient();
        $this->curator($client);
        $public = $this->user('Open Octavia', public: true);
        $this->submission($public, 'Bench by the lock');
        $this->submission($public, 'Settled bench by the lock', SubmissionStatus::Rejected);

        self::assertSame(['Bench by the lock'], $this->titles($client, '/moderate/submissions', 'Octavia'));
        self::assertSame(['Settled bench by the lock'], $this->titles($client, '/moderate/submissions/history', 'Octavia'));
        self::assertCount(1, $this->titles($client, '/moderate/submissions', 'rider#'.$public->getPseudonym()));
    }

    /** History names the deciding curator (DeskRider::colleague), so their name finds their decisions. */
    public function testAColleagueIsFoundByNameOnHistory(): void
    {
        $client = static::createClient();
        $this->curator($client);
        $colleague = $this->user('Colleague Cornelis', ['ROLE_CURATOR']);
        $rider = $this->user('Some Rider');
        $this->submission($rider, 'Decided by a colleague', SubmissionStatus::Approved, decidedBy: $colleague);

        self::assertSame(['Decided by a colleague'], $this->titles($client, '/moderate/submissions/history', 'Cornelis'));
    }
}
