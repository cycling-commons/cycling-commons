<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Account;

use App\Entity\User;
use App\Service\UserDeletionService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Delete accounts nobody confirmed within {@see DAYS} days.
 *
 * An unconfirmed account is an address somebody typed, not yet a person who
 * joined: bots sign up strangers (2026-09-28), and each row kept a stranger's
 * address on file for good. So no warning mail, unlike {@see DormancySweep}:
 * the only mail we could send goes to the same stranger.
 *
 * Left alone: an account that ever signed in (it did, before sign-in needed a
 * confirmed address) and any account with a role beyond ROLE_USER (operators
 * make those by hand). Deletion is the same {@see UserDeletionService::purge()}
 * a rider asks for, one account per transaction
 * ({@see UserDeletionService::eraseInBatch()}): an account whose deletion
 * fails stays whole, is logged by id and counted as failed, and the sweep goes
 * on.
 *
 * @see docs/specs/account-and-auth.md §6.7
 *
 * @api
 */
final class UnverifiedSweep
{
    public const int DAYS = 7;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserDeletionService $deletions,
    ) {
    }

    /**
     * @return array{deleted: int, failed: int, considered: int}
     */
    public function run(\DateTimeImmutable $now, bool $dryRun = false): array
    {
        /** @var list<User> $candidates */
        $candidates = $this->em->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.emailVerified = false')
            ->andWhere('u.lastLoginAt IS NULL')
            ->andWhere('u.createdAt <= :cutoff')
            ->setParameter('cutoff', $now->modify(sprintf('-%d days', self::DAYS)))
            ->orderBy('u.id')
            ->getQuery()
            ->getResult();

        // Roles live in a JSON column; the list is short, so filter here.
        $ids = array_map(
            static fn (User $user): int => (int) $user->getId(),
            array_filter($candidates, static fn (User $user): bool => [] === array_diff($user->getRoles(), ['ROLE_USER'])),
        );

        $deleted = 0;
        $failed = 0;
        foreach ($ids as $id) {
            if ($dryRun) {
                ++$deleted;
                continue;
            }
            // By id: a failed deletion before this one detached every loaded account.
            $user = $this->em->find(User::class, $id);
            if (!$user instanceof User) {
                continue;
            }
            if ($this->deletions->eraseInBatch($user)) {
                ++$deleted;
            } else {
                ++$failed;
            }
        }

        return ['deleted' => $deleted, 'failed' => $failed, 'considered' => \count($candidates)];
    }
}
