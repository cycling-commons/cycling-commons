<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Final-review fix: with the unique canonical-display-name index (spec
 * 2026-07-14), `app:user:create` defaults the display name to the email
 * local part — so creating "curator@foo.org" then "curator@bar.org" both
 * default to "curator" and collide. The command must surface a friendly
 * error, not an uncaught Doctrine\DBAL\Exception\UniqueConstraintViolationException.
 *
 * Test isolation: DAMA\DoctrineTestBundle wraps each test in a rolled-back
 * transaction.
 */
final class CreateUserCommandTest extends KernelTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
    }

    /** @param array<string,string> $args */
    private function runCreate(array $args): CommandTester
    {
        $app = new Application(self::$kernel);
        $tester = new CommandTester($app->find('app:user:create'));
        $tester->execute($args);

        return $tester;
    }

    public function testDisplayNameCollisionFromEmailLocalPartFailsWithFriendlyError(): void
    {
        $this->runCreate([
            'email' => 'curator@foo.org',
            'password' => 'securepass12345!',
        ])->assertCommandIsSuccessful();

        $tester = $this->runCreate([
            'email' => 'curator@bar.org',
            'password' => 'securepass12345!',
        ]);

        self::assertSame(1, $tester->getStatusCode(), 'a colliding default display name must fail, not crash');
        self::assertStringContainsString('already taken', $tester->getDisplay());
        self::assertStringContainsString('--display-name', $tester->getDisplay());

        /** @var UserRepository $repo */
        $repo = static::getContainer()->get(UserRepository::class);
        self::assertNull(
            $repo->findOneBy(['email' => 'curator@bar.org']),
            'the failed second user must not have been persisted',
        );
    }

    public function testDistinctDisplayNameOptionAvoidsTheCollision(): void
    {
        $this->runCreate([
            'email' => 'curator@foo.org',
            'password' => 'securepass12345!',
        ])->assertCommandIsSuccessful();

        $tester = $this->runCreate([
            'email' => 'curator@bar.org',
            'password' => 'securepass12345!',
            '--display-name' => 'Curator Bar',
        ]);

        $tester->assertCommandIsSuccessful();

        /** @var UserRepository $repo */
        $repo = static::getContainer()->get(UserRepository::class);
        $user = $repo->findOneBy(['email' => 'curator@bar.org']);
        self::assertInstanceOf(User::class, $user);
        self::assertSame('Curator Bar', $user->getDisplayName());
    }
}
