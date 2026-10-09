<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Account;

use App\Account\UnverifiedSweep;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Accounts never confirmed within seven days are deleted
 * (account-and-auth.md §6.7). Bots sign up strangers' addresses; without this
 * each one kept a stranger's address on file for good.
 */
final class UnverifiedSweepTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private \DateTimeImmutable $now;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->now = new \DateTimeImmutable('2026-09-29T12:00:00+00:00');
    }

    /** @param list<string> $roles */
    private function account(string $email, int $daysOld, bool $verified = false, bool $signedIn = false, array $roles = []): int
    {
        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Someone');
        $user->setEmailVerified($verified);
        $user->setRoles($roles);
        $user->setPassword('x');
        if ($signedIn) {
            $user->recordLogin($this->now->modify('-1 day'));
        }
        $this->em->persist($user);
        $this->em->flush();

        // No setter on purpose: nothing but a test should ever move it.
        $this->em->getConnection()->executeStatement(
            'UPDATE users SET created_at = :at WHERE id = :id',
            ['at' => $this->now->modify("-{$daysOld} days")->format('Y-m-d H:i:sP'), 'id' => $user->getId()],
        );

        return (int) $user->getId();
    }

    private function exists(int $id): bool
    {
        $this->em->clear();

        return null !== $this->em->find(User::class, $id);
    }

    /** @return array{deleted: int, failed: int, considered: int} */
    private function sweep(bool $dryRun = false): array
    {
        $result = self::getContainer()->get(UnverifiedSweep::class)->run($this->now, $dryRun);
        $this->em->flush();

        return $result;
    }

    public function testAnUnconfirmedAccountOlderThanSevenDaysIsDeleted(): void
    {
        $id = $this->account('bot-victim@example.com', daysOld: 8);

        self::assertSame(1, $this->sweep()['deleted']);
        self::assertFalse($this->exists($id));
    }

    public function testAnUnconfirmedAccountYoungerThanSevenDaysStays(): void
    {
        $id = $this->account('slow-reader@example.com', daysOld: 6);

        self::assertSame(0, $this->sweep()['deleted']);
        self::assertTrue($this->exists($id));
    }

    public function testAConfirmedAccountStays(): void
    {
        $id = $this->account('rider@example.com', daysOld: 400, verified: true);

        $this->sweep();
        self::assertTrue($this->exists($id));
    }

    /** Signed in before sign-in required a confirmed address: a person, with their own history. */
    public function testAnUnconfirmedAccountThatHasSignedInStays(): void
    {
        $id = $this->account('early-rider@example.com', daysOld: 90, signedIn: true);

        $this->sweep();
        self::assertTrue($this->exists($id));
    }

    /** Made by an operator (app:user:create), never through the sign-up form. */
    public function testAnAccountWithAnElevatedRoleStays(): void
    {
        $id = $this->account('curator@example.com', daysOld: 30, roles: ['ROLE_CURATOR']);

        $this->sweep();
        self::assertTrue($this->exists($id));
    }

    /**
     * Each account is erased in its own transaction: one that fails is rolled
     * back whole, logged by id without its address, and the sweep goes on,
     * also after a failed flush closed the EntityManager.
     */
    public function testOneFailedDeletionRollsBackThatAccountOnlyAndTheSweepGoesOn(): void
    {
        $throws = $this->account('sweep-throws@example.com', daysOld: 9);
        $flushFails = $this->account('sweep-flush-fails@example.com', daysOld: 9);
        $fine = $this->account('sweep-fine@example.com', daysOld: 9);
        $logger = new SabotagedErasure();
        $deletions = SabotagedErasure::deletions(self::getContainer(), [$throws => 'throw', $flushFails => 'flush'], $logger);

        $result = (new UnverifiedSweep($this->em, $deletions))->run($this->now);

        self::assertSame(['deleted' => 1, 'failed' => 2], array_intersect_key($result, ['deleted' => 1, 'failed' => 1]));
        self::assertTrue($this->exists($throws));
        self::assertTrue($this->exists($flushFails));
        self::assertFalse($this->exists($fine));
        self::assertSame([$throws, $flushFails], array_map(static fn (array $r): mixed => $r['context']['user_id'] ?? null, $logger->records));
        self::assertStringNotContainsString('@example.com', json_encode($logger->records, JSON_THROW_ON_ERROR));
    }

    public function testADryRunCountsButDeletesNothing(): void
    {
        $id = $this->account('dry@example.com', daysOld: 10);

        self::assertSame(1, $this->sweep(dryRun: true)['deleted']);
        self::assertTrue($this->exists($id));
    }

    public function testTheCommandIsADryRunWithoutForce(): void
    {
        $id = $this->account('command@example.com', daysOld: 10);
        $tester = new CommandTester((new Application(self::$kernel))->find('app:accounts:purge-unverified'));

        $tester->execute([]);
        self::assertTrue($this->exists($id));
        self::assertStringContainsString('Dry run', $tester->getDisplay());

        $tester->execute(['--force' => true]);
        $tester->assertCommandIsSuccessful();
        self::assertFalse($this->exists($id));
    }
}
