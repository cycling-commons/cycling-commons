<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Approved in-site translations of the region keys move into the DB labels.
 *
 * Version20261005120100 seeded region.labels and country.labels from the YAML
 * catalogues, but a rider-proposed, approved translation (translation_overlay)
 * won over the YAML at runtime, so the site showed it. The region: section has
 * left the YAML, so OverlayCatalogueLoader no longer applies those rows:
 * region.<slug>.label merges into region.labels, region.all_<cc>.label into
 * country.labels, overlay value winning per locale.
 *
 * absent_at is ignored on purpose: the sync marks the keys absent once they
 * leave the YAML, whether it runs before or after this migration.
 * Idempotent, and a no-op without overlays. A non-object labels value is {}.
 */
final class Version20261005120200 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'region.labels / country.labels: fold approved overlays of region.<slug>.label and region.all_<cc>.label';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE region r
               SET labels = (CASE WHEN jsonb_typeof(r.labels) = 'object' THEN r.labels ELSE '{}'::jsonb END) || o.m
              FROM (
                SELECT substring(e.message_key FROM '^region\.([^.]+)\.label$') AS slug,
                       jsonb_object_agg(o.locale, o.value) AS m
                  FROM translation_overlay o
                  JOIN translation_entry e ON e.id = o.entry_id
                 WHERE e.message_key ~ '^region\.[^.]+\.label$' AND e.message_key !~ '^region\.all_[a-z]{2}\.label$'
                 GROUP BY 1
              ) o
             WHERE r.slug = o.slug
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE country c
               SET labels = (CASE WHEN jsonb_typeof(c.labels) = 'object' THEN c.labels ELSE '{}'::jsonb END) || o.m
              FROM (
                SELECT upper(substring(e.message_key FROM '^region\.all_([a-z]{2})\.label$')) AS code,
                       jsonb_object_agg(o.locale, o.value) AS m
                  FROM translation_overlay o
                  JOIN translation_entry e ON e.id = o.entry_id
                 WHERE e.message_key ~ '^region\.all_[a-z]{2}\.label$'
                 GROUP BY 1
              ) o
             WHERE c.code = o.code
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // Nothing to undo: the merged values are what the site already showed.
        $this->addSql('SELECT 1');
    }
}
