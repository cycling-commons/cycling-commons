<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Service;

use App\Entity\AdminActionLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Writes one immutable audit row per administrative action.
 */
final class AdminActionLogger
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function log(?User $actor, string $action, ?User $targetUser, ?string $note = null): AdminActionLog
    {
        $entry = new AdminActionLog($actor, $action, $targetUser, $note);
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }
}
