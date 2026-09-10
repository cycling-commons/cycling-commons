<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog\Import;

/**
 * Shared item upsert by (source, source_ref, letter). State is set only on INSERT. Curator-edited rows keep content.
 *
 * @see docs/specs/catalog-data-model.md §3
 *
 * @api
 */
final class ItemUpsert
{
    /**
     * Recompute-owned keys. Survive a re-seed only if `route` is byte-identical.
     *
     * @see docs/specs/climb-elevation.md §4
     */
    public const array MEASURED_KEYS = [
        'length', 'gain', 'footEle', 'summitEle', 'avgGradient', 'maxGradient',
        'grad', 'lineGrad', 'demSource', 'binM', 'steepWindowM', 'steep',
    ];

    public const string SQL = <<<'SQL'
        INSERT INTO item (letter, name, geom, country_code, subdivision_id, state, source, source_ref, attributes, created_at, updated_at, imported_at)
        VALUES (:letter, :name, ST_SetSRID(ST_GeomFromGeoJSON(:geom), 4326), :cc, :sub, :state, :source, :ref, :attrs, NOW(), NOW(), NOW())
        ON CONFLICT (source, source_ref, letter) DO UPDATE SET
          name = CASE WHEN EXISTS (SELECT 1 FROM change_history ch WHERE ch.item_id = item.id) THEN item.name ELSE EXCLUDED.name END,
          geom = CASE WHEN EXISTS (SELECT 1 FROM change_history ch WHERE ch.item_id = item.id) THEN item.geom ELSE EXCLUDED.geom END,
          country_code = CASE WHEN EXISTS (SELECT 1 FROM change_history ch WHERE ch.item_id = item.id) THEN item.country_code ELSE EXCLUDED.country_code END,
          subdivision_id = CASE WHEN EXISTS (SELECT 1 FROM change_history ch WHERE ch.item_id = item.id) THEN item.subdivision_id ELSE EXCLUDED.subdivision_id END,
          attributes = CASE WHEN EXISTS (SELECT 1 FROM change_history ch WHERE ch.item_id = item.id) THEN item.attributes
                            ELSE EXCLUDED.attributes || CASE
                              WHEN item.letter = 'N' AND item.attributes -> 'route' = EXCLUDED.attributes -> 'route'
                              THEN (SELECT COALESCE(jsonb_object_agg(mk, item.attributes -> mk), '{}'::jsonb)
                                    FROM unnest(ARRAY['length','gain','footEle','summitEle','avgGradient','maxGradient','grad','lineGrad','demSource','binM','steepWindowM','steep']) AS mk
                                    WHERE jsonb_exists(item.attributes, mk))
                              ELSE '{}'::jsonb
                            END
                       END,
          updated_at = CASE WHEN EXISTS (SELECT 1 FROM change_history ch WHERE ch.item_id = item.id) THEN item.updated_at
                            WHEN (item.name, ST_AsEWKB(item.geom), item.country_code, item.subdivision_id, item.attributes)
                            IS DISTINCT FROM (EXCLUDED.name, ST_AsEWKB(EXCLUDED.geom), EXCLUDED.country_code, EXCLUDED.subdivision_id, EXCLUDED.attributes)
                       THEN NOW() ELSE item.updated_at END,
          imported_at = NOW()
        SQL;

    /**
     * Upsert without the change_history guard. One named `--overwrite-ref` at a time.
     *
     * @see docs/specs/catalog-data-model.md §3
     *
     * @api
     */
    public const string SQL_OVERWRITE_EDITED = <<<'SQL'
        INSERT INTO item (letter, name, geom, country_code, subdivision_id, state, source, source_ref, attributes, created_at, updated_at, imported_at)
        VALUES (:letter, :name, ST_SetSRID(ST_GeomFromGeoJSON(:geom), 4326), :cc, :sub, :state, :source, :ref, :attrs, NOW(), NOW(), NOW())
        ON CONFLICT (source, source_ref, letter) DO UPDATE SET
          name = EXCLUDED.name,
          geom = EXCLUDED.geom,
          country_code = EXCLUDED.country_code,
          subdivision_id = EXCLUDED.subdivision_id,
          attributes = EXCLUDED.attributes || CASE
            WHEN item.letter = 'N' AND item.attributes -> 'route' = EXCLUDED.attributes -> 'route'
            THEN (SELECT COALESCE(jsonb_object_agg(mk, item.attributes -> mk), '{}'::jsonb)
                  FROM unnest(ARRAY['length','gain','footEle','summitEle','avgGradient','maxGradient','grad','lineGrad','demSource','binM','steepWindowM','steep']) AS mk
                  WHERE jsonb_exists(item.attributes, mk))
            ELSE '{}'::jsonb
          END,
          updated_at = CASE WHEN (item.name, ST_AsEWKB(item.geom), item.country_code, item.subdivision_id, item.attributes)
                            IS DISTINCT FROM (EXCLUDED.name, ST_AsEWKB(EXCLUDED.geom), EXCLUDED.country_code, EXCLUDED.subdivision_id, EXCLUDED.attributes)
                       THEN NOW() ELSE item.updated_at END,
          imported_at = NOW()
        SQL;
}
