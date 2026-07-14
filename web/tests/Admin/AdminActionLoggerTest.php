<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Tests\Admin;

use App\Entity\User;
use App\Repository\AdminActionLogRepository;
use App\Service\AdminActionLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AdminActionLoggerTest extends KernelTestCase
{
    private function persistUser(EntityManagerInterface $em, string $email): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName(strstr($email, '@', true) ?: $email);
        $user->setPassword('x');
        $em->persist($user);
        $em->flush();

        return $user;
    }

    public function testLogPersistsAnAuditRow(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var AdminActionLogger $logger */
        $logger = $container->get(AdminActionLogger::class);
        /** @var AdminActionLogRepository $repo */
        $repo = $container->get(AdminActionLogRepository::class);

        $actor = $this->persistUser($em, 'actor@example.com');
        $target = $this->persistUser($em, 'target@example.com');

        $logger->log($actor, 'unlock', $target, 'cleared lock');

        $rows = $repo->findRecentForUser($target, 10);
        self::assertCount(1, $rows);
        self::assertSame('unlock', $rows[0]->getAction());
        self::assertSame($actor->getId(), $rows[0]->getActor()?->getId());
        self::assertSame('cleared lock', $rows[0]->getNote());
        self::assertNotNull($rows[0]->getCreatedAt());
    }
}
