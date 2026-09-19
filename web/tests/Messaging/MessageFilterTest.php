<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Messaging;

use App\Entity\User;
use App\Messaging\Entity\UserMessage;
use App\Messaging\MessageCategory;
use App\Messaging\MessageService;
use App\Messaging\UserMessageKind;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The inbox's three shelves and its unread switch
 * (docs/specs/moderation-and-contribution.md §7.9).
 */
final class MessageFilterTest extends WebTestCase
{
    private function rider(string $email): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $u = (new User())->setEmail($email)->setDisplayName('Filter Rider');
        $u->setPassword('x');
        $u->setEmailVerified(true)->setEmailVerifiedAt(new \DateTimeImmutable());
        $em->persist($u);
        $em->flush();

        return $u;
    }

    private function send(int $userId, UserMessageKind $kind, int $refId): UserMessage
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $m = static::getContainer()->get(MessageService::class)
            ->sendSystem($userId, $kind, 'submission', $refId, 'A place', 'messages.body.submission_approved');
        self::assertInstanceOf(UserMessage::class, $m);
        $em->flush();

        return $m;
    }

    /**
     * Every kind that can reach an inbox is on a shelf.
     *
     * A new UserMessageKind that nobody filed would be invisible under every
     * filter but "All" — the sort of bug that surfaces only when a rider says
     * they were never told something. RiderReply is the one deliberate
     * exclusion: it is the rider's own answer, addressed to the curator, and
     * listFor() has always kept it out of the inbox.
     */
    public function testEveryInboxKindIsOnAShelf(): void
    {
        $filed = MessageCategory::allFiledKinds();
        $expected = array_values(array_filter(
            UserMessageKind::cases(),
            static fn (UserMessageKind $k): bool => UserMessageKind::RiderReply !== $k,
        ));

        sort($filed);
        sort($expected);
        self::assertSame($expected, $filed, 'a kind on no shelf is a kind no filter can find');
    }

    /** No kind is filed twice, or the chip counts would not add up to "All". */
    public function testNoKindIsOnTwoShelves(): void
    {
        $filed = MessageCategory::allFiledKinds();

        self::assertCount(\count($filed), array_unique(array_map(
            static fn (UserMessageKind $k): string => $k->value,
            $filed,
        )));
    }

    public function testEachShelfListsAndCountsOnlyItsOwn(): void
    {
        static::createClient();
        $rider = $this->rider('shelf-rider@example.test');
        $id = (int) $rider->getId();
        $messages = static::getContainer()->get(MessageService::class);

        $this->send($id, UserMessageKind::SubmissionApproved, 1);
        $this->send($id, UserMessageKind::RouteRejected, 2);
        $this->send($id, UserMessageKind::MediaHiddenPendingReview, 3);
        $this->send($id, UserMessageKind::CuratorMessage, 4);

        self::assertSame(4, $messages->countFor($id));
        self::assertSame(2, $messages->countFor($id, MessageCategory::Contributions));
        self::assertSame(1, $messages->countFor($id, MessageCategory::Notices));
        self::assertSame(1, $messages->countFor($id, MessageCategory::General));

        $notices = $messages->listFor($id, 0, 20, MessageCategory::Notices);
        self::assertCount(1, $notices);
        self::assertSame(UserMessageKind::MediaHiddenPendingReview, $notices[0]->getKind());

        // The chip counts, in one round trip, and they add up to "All".
        $counts = $messages->countsFor($id);
        self::assertSame(4, $counts['total']);
        self::assertSame(4, array_sum($counts['byCategory']));
        self::assertSame(2, $counts['byCategory']['contributions']);
    }

    /**
     * A takedown the rider ASKED for answers something they did, so it files
     * under contributions; a report by a stranger is a notice. The split is by
     * who started it, not by which subsystem wrote the row.
     */
    public function testTakedownOutcomesSplitByWhoStartedIt(): void
    {
        self::assertContains(UserMessageKind::MediaTakedownGranted, MessageCategory::Contributions->kinds());
        self::assertContains(UserMessageKind::MediaRemovedOnReport, MessageCategory::Notices->kinds());
    }

    public function testUnreadOnlyNarrowsAndComposesWithAShelf(): void
    {
        static::createClient();
        $rider = $this->rider('shelf-unread@example.test');
        $id = (int) $rider->getId();
        $messages = static::getContainer()->get(MessageService::class);

        $read = $this->send($id, UserMessageKind::SubmissionApproved, 1);
        $this->send($id, UserMessageKind::SubmissionRejected, 2);
        $this->send($id, UserMessageKind::MediaHiddenPendingReview, 3);
        $messages->markRead($id, [(int) $read->getId()]);

        self::assertSame(2, $messages->countFor($id, null, unreadOnly: true));
        self::assertCount(2, $messages->listFor($id, 0, 20, null, unreadOnly: true));

        // Unread AND a shelf: "unread notices" is a real thing to want.
        self::assertSame(1, $messages->countFor($id, MessageCategory::Contributions, unreadOnly: true));
        self::assertSame(1, $messages->countFor($id, MessageCategory::Notices, unreadOnly: true));
    }

    public function testTheDashboardRendersTheShelvesAndHonoursThem(): void
    {
        $client = static::createClient();
        $rider = $this->rider('shelf-page@example.test');
        $id = (int) $rider->getId();

        $this->send($id, UserMessageKind::SubmissionApproved, 1);
        $this->send($id, UserMessageKind::MediaHiddenPendingReview, 2);
        $client->loginUser($rider, 'main');

        $client->request('GET', '/account/messages');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('msg-filters', (string) $client->getResponse()->getContent());

        $crawler = $client->request('GET', '/account/messages?cat=notices');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('.msg-row')->count(), 'one notice, and only the notice');

        // A shelf that holds nothing says so, and does not read as an empty inbox.
        $client->request('GET', '/account/messages?cat=general');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('No messages match this filter', $html);
        self::assertStringNotContainsString('No messages yet', $html);
    }

    /** A bookmark to a renamed shelf shows everything rather than erroring. */
    public function testAnUnknownShelfFallsBackToEverything(): void
    {
        $client = static::createClient();
        $rider = $this->rider('shelf-unknown@example.test');
        $this->send((int) $rider->getId(), UserMessageKind::SubmissionApproved, 1);
        $client->loginUser($rider, 'main');

        $crawler = $client->request('GET', '/account/messages?cat=not-a-shelf');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('.msg-row')->count());
    }
}
