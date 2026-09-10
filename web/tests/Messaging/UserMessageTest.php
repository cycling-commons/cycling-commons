<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Messaging;

use App\Entity\User;
use App\Messaging\Entity\UserMessage;
use App\Messaging\UserMessageKind;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UserMessageTest extends KernelTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function user(EntityManagerInterface $em, string $email): User
    {
        $u = (new User())->setEmail($email)->setDisplayName('U');
        $u->setPassword('x');
        $em->persist($u);
        $em->flush();

        return $u;
    }

    public function testPersistsAndReadsBack(): void
    {
        self::bootKernel();
        $em = $this->em();
        $u = $this->user($em, 'msg1@test.test');
        $m = new UserMessage($u->getId(), UserMessageKind::SubmissionApproved, 'system', null,
            'submission', 42, 'SUB-42', 'messages.body.submission_approved', ['%title%' => 'Old pump'], null);
        $em->persist($m);
        $em->flush();
        $em->clear();

        $found = $em->find(UserMessage::class, $m->getId());
        self::assertSame(UserMessageKind::SubmissionApproved, $found->getKind());
        self::assertSame('SUB-42', $found->getRefLabel());
        self::assertSame(['%title%' => 'Old pump'], $found->getBodyParams());
        self::assertFalse($found->isRead());
        $found->markRead();
        $em->flush();
        $em->clear();
        self::assertTrue($em->find(UserMessage::class, $m->getId())->isRead());
    }

    public function testCascadesWithAccountDeletion(): void
    {
        self::bootKernel();
        $em = $this->em();
        $u = $this->user($em, 'msg2@test.test');
        $uid = $u->getId();
        $em->persist(new UserMessage($uid, UserMessageKind::CuratorMessage, 'curator', 9,
            'correction', 7, 'Spa · Sankt Vith', null, null, 'Please clarify the gate.'));
        $em->flush();

        // DB-level cascade (M10): deleting the user removes their messages, no hook needed.
        $em->remove($u);
        $em->flush();
        self::assertSame(0, (int) $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM user_message WHERE user_id = :u', ['u' => $uid],
        ));
    }
}
