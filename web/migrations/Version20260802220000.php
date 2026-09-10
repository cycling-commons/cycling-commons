<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Settings stop being integer-only (system-configuration.md §2).
 *
 * The registry always said the first non-integer setting would be a real
 * schema change rather than something sneaking in behind a generic value.
 * It arrived: `app.alert_emails`, the addresses operational alerts go to,
 * which have to be editable when the usual reader is away
 * (docs/specs/photo-uploads.md §6d).
 *
 * One column still, now textual, with the definition reading each value back
 * into its own type. Widening INT to TEXT is lossless, so existing thresholds
 * survive untouched.
 */
final class Version20260802220000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'system_setting.setting_value: INT -> TEXT so settings can hold text';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE system_setting ALTER COLUMN setting_value TYPE TEXT USING setting_value::text');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // Anything non-numeric cannot survive the trip back, and dropping it
        // silently would leave alerts pointing nowhere: refuse those rows
        // rather than mangle them.
        $this->addSql("DELETE FROM system_setting WHERE setting_value !~ '^-?[0-9]+$'");
        $this->addSql('ALTER TABLE system_setting ALTER COLUMN setting_value TYPE INT USING setting_value::integer');
    }
}
