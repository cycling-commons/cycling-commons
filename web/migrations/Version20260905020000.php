<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The Wallonia row fills letter O, not I.
 *
 * `Version20260904110000` seeded `wallonie-pivot` with `letters = ["I"]`, the
 * letter "Where to sleep" had before the catalogue was renumbered on
 * 2026-08-25 (I is free now; stays are O). Its 150 rows have carried `O` since
 * that renumbering, so the registry row disagreed with its own rows, and the
 * desk's new source block put the disagreement on screen (2026-09-05).
 *
 * @see docs/specs/data-provider-hierarchy.md §3
 */
final class Version20260905020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'wallonie-pivot fills letter O (stays), the post-renumbering letter its rows carry';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE data_provider SET letters = '["O"]'::jsonb WHERE provider_key = 'wallonie-pivot'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE data_provider SET letters = '["I"]'::jsonb WHERE provider_key = 'wallonie-pivot'
            SQL);
    }
}
