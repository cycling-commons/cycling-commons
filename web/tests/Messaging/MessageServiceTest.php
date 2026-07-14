<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Messaging;

use App\Entity\User;
use App\Messaging\MessageService;
use App\Messaging\UserMessageKind;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MessageServiceTest extends KernelTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function svc(): MessageService
    {
        return static::getContainer()->get(MessageService::class);
    }

    private function user(EntityManagerInterface $em, string $email): User
    {
        $u = (new User())->setEmail($email)->setDisplayName(strstr($email, '@', true) ?: $email);
        $u->setPassword('x');
        $em->persist($u);
        $em->flush();

        return $u;
    }

    public function testSendSystemPersistsWithoutFlushingUntilCallerFlushes(): void
    {
        self::bootKernel();
        $em = $this->em();
        $svc = $this->svc();
        $u = $this->user($em, 'sys1@test.test');

        $m = $svc->sendSystem(
            $u->getId(),
            UserMessageKind::SubmissionApproved,
            'submission',
            42,
            'SUB-42',
            'messages.body.submission_approved',
            ['%title%' => 'Old pump'],
            '  Looks great, thanks!  ',
        );

        // persist-without-flush: the row is not yet visible via a fresh
        // connection query until the test explicitly flushes.
        self::assertSame(0, (int) $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM user_message WHERE id = :id', ['id' => $m->getId() ?? -1],
        ));

        $em->flush();
        $em->clear();

        $found = $em->find(\App\Messaging\Entity\UserMessage::class, $m->getId());
        self::assertSame('system', $found->getSender());
        self::assertNull($found->getSenderId());
        self::assertSame(UserMessageKind::SubmissionApproved, $found->getKind());
        self::assertSame('messages.body.submission_approved', $found->getBodyKey());
        self::assertSame(['%title%' => 'Old pump'], $found->getBodyParams());
        self::assertSame('Looks great, thanks!', $found->getBodyText());
    }

    public function testSendSystemWithoutNoteLeavesBodyTextNull(): void
    {
        self::bootKernel();
        $em = $this->em();
        $svc = $this->svc();
        $u = $this->user($em, 'sys2@test.test');

        $m = $svc->sendSystem(
            $u->getId(),
            UserMessageKind::SubmissionRejected,
            'submission',
            43,
            'SUB-43',
            'messages.body.submission_rejected',
            [],
            '   ',
        );
        $em->flush();
        $em->clear();

        $found = $em->find(\App\Messaging\Entity\UserMessage::class, $m->getId());
        self::assertNull($found->getBodyText());
    }

    public function testSendSystemOverLongNoteThrows(): void
    {
        self::bootKernel();
        $em = $this->em();
        $svc = $this->svc();
        $u = $this->user($em, 'sys3@test.test');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('moderate.error.note_too_long');
        $svc->sendSystem(
            $u->getId(),
            UserMessageKind::SubmissionApproved,
            'submission',
            44,
            'SUB-44',
            'messages.body.submission_approved',
            [],
            str_repeat('x', 2001),
        );
    }

    public function testSendCuratorPersistsAndFlushesImmediately(): void
    {
        self::bootKernel();
        $em = $this->em();
        $svc = $this->svc();
        $rider = $this->user($em, 'rider1@test.test');
        $curator = $this->user($em, 'curator1@test.test');

        $m = $svc->sendCurator(
            $rider->getId(),
            $curator->getId(),
            'correction',
            7,
            'Spa · Sankt Vith',
            'Please clarify the gate location.',
        );

        // sendCurator flushes internally: visible without an extra $em->flush().
        self::assertSame(1, (int) $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM user_message WHERE id = :id', ['id' => $m->getId()],
        ));

        $em->clear();
        $found = $em->find(\App\Messaging\Entity\UserMessage::class, $m->getId());
        self::assertSame('curator', $found->getSender());
        self::assertSame($curator->getId(), $found->getSenderId());
        self::assertSame(UserMessageKind::CuratorMessage, $found->getKind());
        self::assertSame('Please clarify the gate location.', $found->getBodyText());
        self::assertNull($found->getBodyKey());
    }

    public function testSendCuratorRequiresNonEmptyBody(): void
    {
        self::bootKernel();
        $em = $this->em();
        $svc = $this->svc();
        $rider = $this->user($em, 'rider2@test.test');
        $curator = $this->user($em, 'curator2@test.test');

        $this->expectException(\InvalidArgumentException::class);
        $svc->sendCurator($rider->getId(), $curator->getId(), 'correction', 8, 'Label', '   ');
    }

    public function testSendCuratorOverLongBodyThrows(): void
    {
        self::bootKernel();
        $em = $this->em();
        $svc = $this->svc();
        $rider = $this->user($em, 'rider3@test.test');
        $curator = $this->user($em, 'curator3@test.test');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('moderate.error.note_too_long');
        $svc->sendCurator($rider->getId(), $curator->getId(), 'correction', 9, 'Label', str_repeat('y', 2001));
    }

    public function testSendRiderReplyRecipientIsTheCurator(): void
    {
        self::bootKernel();
        $em = $this->em();
        $svc = $this->svc();
        $rider = $this->user($em, 'rider4@test.test');
        $curator = $this->user($em, 'curator4@test.test');

        $m = $svc->sendRiderReply(
            $curator->getId(),
            $rider->getId(),
            'submission',
            10,
            'SUB-10',
            'Here is the extra info you asked for.',
        );

        self::assertSame($curator->getId(), $m->getUserId());
        self::assertSame('rider', $m->getSender());
        self::assertSame($rider->getId(), $m->getSenderId());
        self::assertSame(UserMessageKind::RiderReply, $m->getKind());
        self::assertSame('Here is the extra info you asked for.', $m->getBodyText());

        // flushed immediately, visible without an extra flush
        self::assertSame(1, (int) $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM user_message WHERE id = :id', ['id' => $m->getId()],
        ));
    }

    public function testUnreadCountOnlyCountsUnread(): void
    {
        self::bootKernel();
        $em = $this->em();
        $svc = $this->svc();
        $rider = $this->user($em, 'rider5@test.test');
        $curator = $this->user($em, 'curator5@test.test');

        self::assertSame(0, $svc->unreadCount($rider->getId()));

        $m1 = $svc->sendCurator($rider->getId(), $curator->getId(), 'correction', 1, 'A', 'first');
        $svc->sendCurator($rider->getId(), $curator->getId(), 'correction', 2, 'B', 'second');
        self::assertSame(2, $svc->unreadCount($rider->getId()));

        $entity = $em->find(\App\Messaging\Entity\UserMessage::class, $m1->getId());
        $entity->markRead();
        $em->flush();

        self::assertSame(1, $svc->unreadCount($rider->getId()));
    }

    public function testMarkAllReadZeroesUnreadCount(): void
    {
        self::bootKernel();
        $em = $this->em();
        $svc = $this->svc();
        $rider = $this->user($em, 'rider6@test.test');
        $curator = $this->user($em, 'curator6@test.test');

        $svc->sendCurator($rider->getId(), $curator->getId(), 'correction', 1, 'A', 'first');
        $svc->sendCurator($rider->getId(), $curator->getId(), 'correction', 2, 'B', 'second');
        self::assertSame(2, $svc->unreadCount($rider->getId()));

        $svc->markAllRead($rider->getId());
        self::assertSame(0, $svc->unreadCount($rider->getId()));
    }

    public function testListForOrdersNewestFirst(): void
    {
        self::bootKernel();
        $em = $this->em();
        $svc = $this->svc();
        $rider = $this->user($em, 'rider7@test.test');
        $curator = $this->user($em, 'curator7@test.test');

        $m1 = $svc->sendCurator($rider->getId(), $curator->getId(), 'correction', 1, 'A', 'first');
        $m2 = $svc->sendCurator($rider->getId(), $curator->getId(), 'correction', 2, 'B', 'second');
        $m3 = $svc->sendCurator($rider->getId(), $curator->getId(), 'correction', 3, 'C', 'third');

        $list = $svc->listFor($rider->getId());
        self::assertSame([$m3->getId(), $m2->getId(), $m1->getId()], array_map(
            static fn (\App\Messaging\Entity\UserMessage $m): ?int => $m->getId(),
            $list,
        ));
    }
}
