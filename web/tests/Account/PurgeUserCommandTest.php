<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Account;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:user:purge`: the operator's way to remove accounts that never asked,
 * through the same seam a rider's own deletion uses. Dry run by default,
 * never the last admin, and an unknown email removes nothing at all.
 *
 * Test isolation: DAMA\DoctrineTestBundle wraps each test in a rolled-back
 * transaction.
 */
final class PurgeUserCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em = $em;
    }

    /** @param list<string> $emails */
    private function runPurge(array $emails, bool $force = false): CommandTester
    {
        $app = new Application(self::$kernel);
        $tester = new CommandTester($app->find('app:user:purge'));
        $tester->execute(['email' => $emails] + ($force ? ['--force' => true] : []));

        return $tester;
    }

    /** @param list<string> $roles */
    private function makeUser(string $email, array $roles = []): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName(explode('@', $email)[0]);
        $user->setEmailVerified(true);
        $user->setRoles($roles);
        $user->setPassword('irrelevant');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function find(string $email): ?User
    {
        $this->em->clear();
        /** @var UserRepository $repo */
        $repo = static::getContainer()->get(UserRepository::class);

        return $repo->findOneBy(['email' => $email]);
    }

    public function testADryRunListsAndKeepsTheAccount(): void
    {
        $this->makeUser('probe@example.test');

        $tester = $this->runPurge(['probe@example.test']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('probe@example.test', $tester->getDisplay());
        self::assertStringContainsString('Dry run', $tester->getDisplay());
        self::assertNotNull($this->find('probe@example.test'));
    }

    public function testForceRemovesTheAccount(): void
    {
        $this->makeUser('probe@example.test');
        $this->makeUser('keeper@example.test');

        $tester = $this->runPurge(['probe@example.test'], force: true);

        $tester->assertCommandIsSuccessful();
        self::assertNull($this->find('probe@example.test'));
        self::assertNotNull($this->find('keeper@example.test'), 'only the named account goes');
    }

    public function testAnUnknownEmailRemovesNothing(): void
    {
        $this->makeUser('probe@example.test');

        $tester = $this->runPurge(['probe@example.test', 'nobody@example.test'], force: true);

        self::assertSame(1, $tester->getStatusCode());
        self::assertNotNull($this->find('probe@example.test'), 'one bad email means no account goes');
    }

    public function testTheLastAdminIsRefused(): void
    {
        $this->makeUser('only-admin@example.test', ['ROLE_ADMIN']);

        // Whatever admins the fixtures left, removing every one of them is refused.
        /** @var UserRepository $repo */
        $repo = static::getContainer()->get(UserRepository::class);
        $admins = array_values(array_map(
            static fn (User $u): string => $u->getEmail(),
            array_filter($repo->findAll(), static fn (User $u): bool => \in_array('ROLE_ADMIN', $u->getRoles(), true)),
        ));

        $tester = $this->runPurge($admins, force: true);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('last ROLE_ADMIN', $tester->getDisplay());
        self::assertNotNull($this->find('only-admin@example.test'));
    }
}
