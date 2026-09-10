<?php

// SPDX-License-Identifier: AGPL-3.0-only

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

    /**
     * Two addresses with the same local part both default to the same display
     * name — and that is fine now. Display names stopped being unique by
     * decision (account-and-auth.md §9): the uuid is the identity, so
     * `curator@foo.org` and `curator@bar.org` are simply two accounts that
     * happen to be called "curator".
     */
    public function testTwoAccountsMayShareTheDefaultDisplayName(): void
    {
        $this->runCreate([
            'email' => 'curator@foo.org',
            'password' => 'securepass12345!',
        ])->assertCommandIsSuccessful();

        $this->runCreate([
            'email' => 'curator@bar.org',
            'password' => 'securepass12345!',
        ])->assertCommandIsSuccessful();

        /** @var UserRepository $repo */
        $repo = static::getContainer()->get(UserRepository::class);

        foreach (['curator@foo.org', 'curator@bar.org'] as $email) {
            $user = $repo->findOneBy(['email' => $email]);
            self::assertNotNull($user, $email.' was created');
            self::assertSame('curator', $user->getDisplayName());
        }
    }

    /** Email is the one uniqueness rule left, and it still reports itself clearly. */
    public function testADuplicateEmailFailsWithAFriendlyError(): void
    {
        $this->runCreate([
            'email' => 'twice@example.org',
            'password' => 'securepass12345!',
        ])->assertCommandIsSuccessful();

        $tester = $this->runCreate([
            'email' => 'twice@example.org',
            'password' => 'securepass12345!',
        ]);

        self::assertSame(1, $tester->getStatusCode(), 'a duplicate email must fail, not crash');
        self::assertStringContainsString('already exists', $tester->getDisplay());
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
