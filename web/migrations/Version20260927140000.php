<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bug-report screenshots and curator-room images are checked on the worker.
 *
 * The web host holds the raw bytes as `pending`; the worker scans them and
 * draws them again (`ready`), or refuses them (`refused`, bytes dropped, the
 * reason kept). Rows already here were drawn on the way in, so they start
 * `ready`.
 */
final class Version20260927140000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'bug_screenshot, curator_post_image: state and refusal for worker-side checking';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        foreach (['bug_screenshot', 'curator_post_image'] as $table) {
            $this->addSql("ALTER TABLE {$table} ADD state VARCHAR(12) DEFAULT 'ready' NOT NULL");
            $this->addSql("ALTER TABLE {$table} ADD refusal VARCHAR(64) DEFAULT NULL");
        }
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        foreach (['bug_screenshot', 'curator_post_image'] as $table) {
            $this->addSql("ALTER TABLE {$table} DROP state");
            $this->addSql("ALTER TABLE {$table} DROP refusal");
        }
    }
}
