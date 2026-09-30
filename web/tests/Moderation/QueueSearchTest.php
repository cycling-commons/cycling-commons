<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\Region;
use App\Catalog\Entity\Submission;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use App\Entity\User;
use App\Messaging\CuratorRoom;
use App\Moderation\Entity\ModeratorArea;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The search box on the submissions desk and on History
 * (docs/specs/moderation-and-contribution.md §5.2): a submitter is found by
 * name only when the card shows their name, by pseudonym always, and a
 * submission by its number.
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

        // The title without the kind label History prints beside it, and
        // without the unseen bar's words (moderation-and-contribution.md §5.2f).
        return $crawler->filter('.q-list .q-item .q-title')->each(static function ($n): string {
            $text = $n->text();
            foreach (['.q-kind', '.unseen-note'] as $extra) {
                $found = $n->filter($extra);
                if ($found->count() > 0) {
                    $text = str_replace($found->text(), '', $text);
                }
            }

            return trim($text);
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

    // ── BUG-07: SUB-N and #N are a submission number ────────────────────────

    public function testTheQueueFindsASubmissionByItsNumber(): void
    {
        $client = static::createClient();
        $this->curator($client);
        $rider = $this->user('Number Rider');
        $sub = $this->submission($rider, 'Numbered fountain');
        $this->submission($rider, 'Another fountain');

        foreach (['SUB-'.$sub->getId(), 'sub-'.$sub->getId(), '#'.$sub->getId()] as $q) {
            self::assertSame(['Numbered fountain'], $this->titles($client, '/moderate/submissions', $q), $q);
        }
    }

    public function testASettledSubmissionIsFoundOnHistoryByItsNumber(): void
    {
        $client = static::createClient();
        $this->curator($client);
        $sub = $this->submission($this->user('Settled Rider'), 'Settled fountain', SubmissionStatus::Approved);

        self::assertSame([], $this->titles($client, '/moderate/submissions', 'SUB-'.$sub->getId()));
        self::assertSame(['Settled fountain'], $this->titles($client, '/moderate/submissions/history', 'SUB-'.$sub->getId()));
    }

    public function testTheNumberStaysInsideTheCuratorsArea(): void
    {
        $client = static::createClient();
        $mine = (new Region())->setSlug('qsearch-mine-'.uniqid())->setName('Qsearch mine')->setCountryCode('BE');
        $other = (new Region())->setSlug('qsearch-other-'.uniqid())->setName('Qsearch other')->setCountryCode('NL');
        $this->em()->persist($mine);
        $this->em()->persist($other);
        $this->em()->flush();
        $curator = $this->curator($client, 'Scoped Curator');
        $this->em()->persist(new ModeratorArea((int) $curator->getId(), (int) $mine->getId(), null));
        $this->em()->flush();

        $outside = $this->submission($this->user('Far Rider'), 'Fountain elsewhere', regionId: (int) $other->getId());

        self::assertSame([], $this->titles($client, '/moderate/submissions', 'SUB-'.$outside->getId()));
    }

    /**
     * The room links a post to the place the submission is: the open queue
     * while it waits, History once decided, nowhere while it is held.
     */
    public function testTheRoomLinksAPostToWhereTheSubmissionIs(): void
    {
        $client = static::createClient();
        $author = $this->curator($client, 'Room Author');
        $rider = $this->user('Room Rider');
        $open = $this->submission($rider, 'Room open fountain');
        $settled = $this->submission($rider, 'Room settled fountain', SubmissionStatus::Approved);
        $held = $this->submission($rider, 'Room held fountain');

        $room = static::getContainer()->get(CuratorRoom::class);
        foreach ([$open, $settled, $held] as $sub) {
            $room->post((int) $author->getId(), null, null, 'About this one.', (int) $sub->getId(), title: 'A post');
        }
        // Held after the post was written, as a post cannot link a held card.
        $held->escalate((int) $author->getId(), 'Suspected illegal content.');
        $this->em()->flush();

        $crawler = $client->request('GET', '/moderate/room');
        self::assertResponseIsSuccessful();
        $hrefs = [];
        $crawler->filter('.rm-post')->each(function ($post) use (&$hrefs): void {
            $link = $post->filter('.rm-about a');
            $hrefs[trim($post->filter('.rm-about')->text())] = $link->count() > 0 ? (string) $link->attr('href') : null;
        });

        $openHref = $this->hrefFor($hrefs, (int) $open->getId());
        $settledHref = $this->hrefFor($hrefs, (int) $settled->getId());
        self::assertNotNull($openHref);
        self::assertNotNull($settledHref);
        self::assertNull($this->hrefFor($hrefs, (int) $held->getId()), 'a held submission is on no desk');

        self::assertSame(['Room open fountain'], $this->titles($client, (string) parse_url($openHref, \PHP_URL_PATH), $this->qOf($openHref)));
        self::assertStringContainsString('/moderate/submissions/history', $settledHref);
        self::assertSame(['Room settled fountain'], $this->titles($client, (string) parse_url($settledHref, \PHP_URL_PATH), $this->qOf($settledHref)));
    }

    /** @param array<string, ?string> $hrefs the post's "About ..." label => its link */
    private function hrefFor(array $hrefs, int $id): ?string
    {
        foreach ($hrefs as $label => $href) {
            if (1 === preg_match('/(?:SUB-|#)'.$id.'\b/', $label)) {
                return $href;
            }
        }
        self::fail('no post about SUB-'.$id);
    }

    private function qOf(string $href): string
    {
        parse_str((string) parse_url($href, \PHP_URL_QUERY), $query);

        return \is_string($query['q'] ?? null) ? $query['q'] : '';
    }
}
