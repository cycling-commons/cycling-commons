<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog\Import;

/**
 * The single item-upsert statement shared by every catalog-seeding command
 * (harvest {@see \App\Catalog\Command\ImportCatalogCommand} and manual
 * {@see \App\Catalog\Command\SeedManualCatalogCommand}).
 *
 * Upserts by (source, source_ref, letter). Lifecycle `state` is set only on
 * INSERT, never on update. A row that carries an approved curator edit -
 * marked by the existence of change_history rows
 * (ModerationService::applyEdit) - keeps its DB content and only refreshes
 * imported_at, so a re-run never clobbers a moderation-approved edit.
 *
 * Bind: letter, name, geom (GeoJSON string), cc, sub, state, source, ref, attrs.
 *
 * @see docs/specs/catalog-data-model.md §3
 *
 * @api Referenced by the catalog seeding commands.
 */
final class ItemUpsert
{
    public const string SQL = <<<'SQL'
        INSERT INTO item (letter, name, geom, country_code, subdivision_id, state, source, source_ref, attributes, created_at, updated_at, imported_at)
        VALUES (:letter, :name, ST_SetSRID(ST_GeomFromGeoJSON(:geom), 4326), :cc, :sub, :state, :source, :ref, :attrs, NOW(), NOW(), NOW())
        ON CONFLICT (source, source_ref, letter) DO UPDATE SET
          name = CASE WHEN EXISTS (SELECT 1 FROM change_history ch WHERE ch.item_id = item.id) THEN item.name ELSE EXCLUDED.name END,
          geom = CASE WHEN EXISTS (SELECT 1 FROM change_history ch WHERE ch.item_id = item.id) THEN item.geom ELSE EXCLUDED.geom END,
          country_code = CASE WHEN EXISTS (SELECT 1 FROM change_history ch WHERE ch.item_id = item.id) THEN item.country_code ELSE EXCLUDED.country_code END,
          subdivision_id = CASE WHEN EXISTS (SELECT 1 FROM change_history ch WHERE ch.item_id = item.id) THEN item.subdivision_id ELSE EXCLUDED.subdivision_id END,
          attributes = CASE WHEN EXISTS (SELECT 1 FROM change_history ch WHERE ch.item_id = item.id) THEN item.attributes ELSE EXCLUDED.attributes END,
          updated_at = CASE WHEN EXISTS (SELECT 1 FROM change_history ch WHERE ch.item_id = item.id) THEN item.updated_at
                            WHEN (item.name, ST_AsEWKB(item.geom), item.country_code, item.subdivision_id, item.attributes)
                            IS DISTINCT FROM (EXCLUDED.name, ST_AsEWKB(EXCLUDED.geom), EXCLUDED.country_code, EXCLUDED.subdivision_id, EXCLUDED.attributes)
                       THEN NOW() ELSE item.updated_at END,
          imported_at = NOW()
        SQL;

    /**
     * The same upsert with the change_history guard removed, so a re-seed
     * DOES overwrite a row that carries an approved edit.
     *
     * This exists for one situation: the seed definition has been corrected
     * (a re-measured geometry, a fixed attribute) and the owner decides the
     * corrected seed beats the edit that is pinning the row. It is never the
     * default and never applies to a whole run - the only caller is
     * {@see \App\Catalog\Command\SeedManualCatalogCommand}'s `--overwrite-ref`
     * option, which names one source_ref at a time and errors on a ref that
     * matches no pin, so a typo can not silently widen the blast radius.
     *
     * The change_history rows themselves are left intact: the edit stays in
     * the audit trail, only the current content is replaced.
     *
     * Bind: identical to {@see self::SQL}.
     *
     * @see docs/specs/catalog-data-model.md §3
     *
     * @api Referenced by the manual catalog seeding command.
     */
    public const string SQL_OVERWRITE_EDITED = <<<'SQL'
        INSERT INTO item (letter, name, geom, country_code, subdivision_id, state, source, source_ref, attributes, created_at, updated_at, imported_at)
        VALUES (:letter, :name, ST_SetSRID(ST_GeomFromGeoJSON(:geom), 4326), :cc, :sub, :state, :source, :ref, :attrs, NOW(), NOW(), NOW())
        ON CONFLICT (source, source_ref, letter) DO UPDATE SET
          name = EXCLUDED.name,
          geom = EXCLUDED.geom,
          country_code = EXCLUDED.country_code,
          subdivision_id = EXCLUDED.subdivision_id,
          attributes = EXCLUDED.attributes,
          updated_at = CASE WHEN (item.name, ST_AsEWKB(item.geom), item.country_code, item.subdivision_id, item.attributes)
                            IS DISTINCT FROM (EXCLUDED.name, ST_AsEWKB(EXCLUDED.geom), EXCLUDED.country_code, EXCLUDED.subdivision_id, EXCLUDED.attributes)
                       THEN NOW() ELSE item.updated_at END,
          imported_at = NOW()
        SQL;
}
