<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Translation;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Cold-loads overlay rows for one locale. Public $loads is for tests only.
 *
 * @api
 */
final class OverlayCatalogueLoader
{
    public int $loads = 0;

    public function __construct(
        private readonly Connection $db,
    ) {
    }

    /**
     * @return array<string, string> message_key => value
     */
    public function load(string $locale): array
    {
        ++$this->loads;

        // The consent contracts are excluded HERE as well as at proposal
        // time, so a row that reached the table by any other road (a direct
        // write, a rule that changes later) still cannot reword them.
        /** @var array<string, string> $rows */
        $rows = $this->db->fetchAllKeyValue(
            <<<'SQL'
            SELECT e.message_key, o.value
            FROM translation_overlay o
            JOIN translation_entry e ON e.id = o.entry_id
            WHERE o.locale = :locale AND e.absent_at IS NULL
              AND e.message_key NOT IN (:protected)
            SQL,
            ['locale' => $locale, 'protected' => ProtectedKeys::KEYS],
            ['protected' => ArrayParameterType::STRING],
        );

        return $rows;
    }
}
