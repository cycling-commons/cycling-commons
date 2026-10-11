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
        $candidates = $this->candidates($now);
        $deleted = 0;
        $failed = 0;
        foreach ($this->eligibleIds($candidates) as $id) {
            if ($dryRun) {
                ++$deleted;
                continue;
            }
            $outcome = $this->handle($id);
            if (true === $outcome) {
                ++$deleted;
            } elseif (false === $outcome) {
                ++$failed;
            }
        }

        return ['deleted' => $deleted, 'failed' => $failed, 'considered' => \count($candidates)];
    }

    /**
     * The accounts the sweep deletes, by id.
     *
     * Public, with {@see handle()}, so the daily erasure check can follow one
     * throwaway account through the real sweep and leave every other alone.
     *
     * @return list<int>
     */
    public function candidateIds(\DateTimeImmutable $now): array
    {
        return $this->eligibleIds($this->candidates($now));
    }

    /**
     * Delete one account.
     *
     * @return bool|null whether it was deleted, or null for an account that is gone
     */
    public function handle(int $id): ?bool
    {
        // By id: a failed deletion before this one detached every loaded account.
        $user = $this->em->find(User::class, $id);
        if (!$user instanceof User) {
            return null;
        }

        return $this->deletions->eraseInBatch($user);
    }

    /** @return list<User> */
    private function candidates(\DateTimeImmutable $now): array
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

        return $candidates;
    }

    /**
     * @param list<User> $candidates
     *
     * @return list<int>
     */
    private function eligibleIds(array $candidates): array
    {
        // Roles live in a JSON column; the list is short, so filter here.
        return array_values(array_map(
            static fn (User $user): int => (int) $user->getId(),
            array_filter($candidates, static fn (User $user): bool => [] === array_diff($user->getRoles(), ['ROLE_USER'])),
        ));
    }
}
