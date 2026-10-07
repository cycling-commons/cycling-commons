<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 *
 * @api
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface, UserLoaderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    public function findByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => User::normalizeEmail($email)]);
    }

    /** The login lookup, case-blind like every other address lookup. */
    #[\Override]
    public function loadUserByIdentifier(string $identifier): ?User
    {
        return $this->findByEmail($identifier);
    }

    /**
     * Count users whose roles JSON contains `$role` (Postgres jsonb containment).
     */
    public function countWithRole(string $role): int
    {
        $sql = 'SELECT COUNT(*) FROM users WHERE roles::jsonb @> :role::jsonb';

        return (int) $this->getEntityManager()->getConnection()
            ->executeQuery($sql, ['role' => json_encode([$role])])
            ->fetchOne();
    }

    public function countAll(): int
    {
        return $this->count([]);
    }

    public function countUnverified(): int
    {
        return $this->count(['emailVerified' => false]);
    }

    public function countLocked(): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.lockedUntil > :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countPendingRemoval(): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.deletionRequestedAt IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Accounts per privacy notice version last seen, newest first; null is "none yet".
     *
     * @return list<array{version: int|null, accounts: int}>
     */
    public function countByPrivacyVersion(): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT privacy_version_seen AS version, COUNT(*) AS accounts FROM users GROUP BY privacy_version_seen ORDER BY privacy_version_seen DESC NULLS LAST',
        );

        return array_map(static fn (array $r): array => [
            'version' => null === $r['version'] ? null : (int) $r['version'],
            'accounts' => (int) $r['accounts'],
        ], $rows);
    }

    /**
     * @return list<User>
     */
    public function recentSignups(int $limit = 10): array
    {
        return $this->findBy([], ['createdAt' => 'DESC'], $limit);
    }
}
