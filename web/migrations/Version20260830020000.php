<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Third-party photo reports get the desk they moved to.
 *
 * From 2026-08-30 a report about a picture is decided at `/moderate/reports`,
 * not at `/moderate/takedowns`, because that is where the DSA record lives and
 * where a decision also sends the reporter their Article 16(5) outcome and the
 * author their Article 17 statement. The takedown desk kept only what an
 * UPLOADER asked us to remove.
 *
 * Reports filed BEFORE that change exist only as `takedown_*` columns on
 * `media_upload`. Without this migration they would have left one desk without
 * arriving at the other: still pending, still hiding a withheld picture, and
 * visible to nobody. So each unresolved third-party request becomes the
 * `content_report` row it would be if it were filed today.
 *
 * Three things worth knowing about the copy:
 *
 * * **The category carries over as the ground, verbatim.** The vocabularies
 *   were merged rather than mapped, `intimate_or_child` is spelled the same in
 *   both, and the four legacy names are accepted as grounds.
 *   `identifiable_self` and `identifiable_other` are not renamed to
 *   `personal_data` here: a row should keep the words the reporter chose.
 * * **The reporter hash carries over as `reporter_key`.** Both are keyed hashes
 *   of an address that was never stored, and rewriting one into the other's
 *   key is impossible by design. The rate limit reads the new column from now
 *   on; an old row simply keeps its old hash and matches nothing new.
 * * **`ON CONFLICT DO NOTHING` on a deterministic id**, so re-running this is
 *   free. The id is derived from the upload's, which gives one report per
 *   migrated request and no duplicates on a second pass.
 *
 * Reports that were already resolved are left alone: they have an answer, and
 * manufacturing an open row for a closed matter would put work on a desk that
 * somebody already did.
 *
 * @see docs/specs/2026-08-30-one-report-route-design.md §5
 */
final class Version20260830020000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Carry unresolved third-party photo reports into content_report, where they are now decided.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO content_report (
                id, target_type, target_id, target_label, ground, reason,
                reporter_contact, reporter_key, status, created_at
            )
            SELECT
                md5(m.id::text || ':takedown-merge')::uuid,
                'photo',
                m.id::text,
                NULL,
                m.takedown_category,
                COALESCE(NULLIF(TRIM(m.takedown_reason), ''), '(carried over from the takedown desk)'),
                m.takedown_contact,
                COALESCE(m.takedown_reporter_hash, 'migrated'),
                'open',
                m.takedown_requested_at
            FROM media_upload m
            WHERE m.takedown_source = 'third_party'
              AND m.takedown_requested_at IS NOT NULL
              AND m.takedown_resolved_at IS NULL
              AND m.objects_deleted_at IS NULL
              AND m.takedown_category IS NOT NULL
            ON CONFLICT (id) DO NOTHING
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // Only the rows this migration made: the derived id is what says so.
        $this->addSql(<<<'SQL'
            DELETE FROM content_report
            WHERE target_type = 'photo'
              AND id = md5(target_id || ':takedown-merge')::uuid
            SQL);
    }
}
