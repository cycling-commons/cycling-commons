<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Repository;

use App\Entity\AdminActionLog;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AdminActionLog>
 *
 * @api
 */
class AdminActionLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdminActionLog::class);
    }

    /**
     * @return list<AdminActionLog>
     */
    public function findRecentForUser(User $user, int $limit = 10): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.targetUser = :u')
            ->setParameter('u', $user)
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
