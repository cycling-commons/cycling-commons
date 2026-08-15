<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Wikipedia context for region pages (owner idea 2026-08-14, built
 * 2026-08-16): a short encyclopedic lead per locale, harvested at BUILD time
 * by tools/wikimedia/region_context.py into a reviewable artifact and
 * imported by app:regions:import-context - never fetched at runtime, so the
 * page keeps zero external dependencies.
 *
 * Shape: {"en": {"title", "extract", "url"}, "fr": {...}, ...} - one entry
 * per locale that has a Wikipedia article; readers of a locale without one
 * fall back to English at render time rather than to nothing.
 *
 * The text is CC BY-SA 4.0, which is why it travels with its article URL:
 * the page shows the extract verbatim with a named-and-linked attribution
 * line, and /credits carries the Wikipedia row.
 */
final class Version20260816020000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'region: context JSONB for build-time Wikipedia extracts (CC BY-SA attribution travels with the text)';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE region ADD context JSONB DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE region DROP context');
    }
}
