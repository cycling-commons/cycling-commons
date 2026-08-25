<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Translation;

use App\Entity\User;
use App\Service\UserDeletionHookInterface;
use Doctrine\DBAL\Connection;

/**
 * Account deletion: null identity on licensed translation rows; keep the strings.
 *
 * @see docs/specs/translations.md §3.2
 *
 * @api
 */
final class TranslationDeletionHook implements UserDeletionHookInterface
{
    public function __construct(private readonly Connection $db)
    {
    }

    #[\Override]
    public function preDelete(User $user): void
    {
        $id = (int) $user->getId();

        $this->db->executeStatement(
            'UPDATE translation_proposal SET submitter_id = NULL WHERE submitter_id = :id',
            ['id' => $id],
        );
        $this->db->executeStatement(
            'UPDATE translation_proposal SET reviewer_id = NULL WHERE reviewer_id = :id',
            ['id' => $id],
        );
        $this->db->executeStatement(
            'UPDATE translation_overlay SET approved_by_id = NULL WHERE approved_by_id = :id',
            ['id' => $id],
        );
    }
}
