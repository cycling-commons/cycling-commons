<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Coverage;

use Doctrine\DBAL\Connection;

/**
 * Test-side equivalent of the pipeline's ensure_schema()
 * (coverage-provider.md §2) — DDL and index names mirror
 * pipeline/coverage/load.py verbatim: coverage_poi is pipeline-owned
 * DDL, excluded from Doctrine's schema_filter, so migrations never create it.
 * Each test builds it inside the DAMA transaction — PostgreSQL DDL is
 * transactional, so the table (and pg_trgm CREATE, when new) rolls back with
 * the test's data. The DELETE guards against stray committed rows from
 * out-of-band runs (the known test-DB failure mode `make test-db-reset` fixes).
 */
trait CoverageSchema
{
    private static function ensureCoverageSchema(Connection $db): void
    {
        // pg_trgm is a trusted extension (PG >= 13): no superuser needed.
        $db->executeStatement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        $db->executeStatement(
            'CREATE TABLE IF NOT EXISTS coverage_poi (
                id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                ref          varchar(160) NOT NULL,
                letter       char(1)      NOT NULL,
                kind         varchar(16),
                name         varchar(255),
                geom         geometry(Point, 4326) NOT NULL,
                tags         jsonb        NOT NULL,
                osm_version  int,
                osm_ts       timestamptz,
                src_region   varchar(64)  NOT NULL,
                country_code char(2),
                region_id    bigint,
                UNIQUE (ref, letter)
            )',
        );
        $db->executeStatement('CREATE INDEX IF NOT EXISTS coverage_poi_geom_idx ON coverage_poi USING GIST (geom)');
        $db->executeStatement('CREATE INDEX IF NOT EXISTS coverage_poi_letter_idx ON coverage_poi (letter)');
        $db->executeStatement('CREATE INDEX IF NOT EXISTS coverage_poi_region_id_idx ON coverage_poi (region_id)');
        $db->executeStatement('CREATE INDEX IF NOT EXISTS coverage_poi_name_trgm_idx ON coverage_poi USING GIN (name gin_trgm_ops)');
        $db->executeStatement('DELETE FROM coverage_poi');
    }

    /**
     * Insert one coverage row (defaults form a valid Belgian water node).
     * `region_id`/`country_code` default to NULL/BE; pass them to exercise the
     * region-scope arms (region-scoping-design.md §6).
     *
     * @param array<string, mixed> $overrides ref|letter|kind|name|lat|lng|tags|region_id|country_code
     */
    private static function insertCoveragePoi(Connection $db, array $overrides = []): void
    {
        $row = $overrides + [
            'ref' => 'node/'.random_int(100000, 999999),
            'letter' => 'C',
            'kind' => null,
            'name' => 'Fontaine test',
            'lat' => 50.4,
            'lng' => 5.8,
            'tags' => ['amenity' => 'drinking_water'],
            'region_id' => null,
            'country_code' => 'BE',
        ];
        $db->executeStatement(
            'INSERT INTO coverage_poi (ref, letter, kind, name, geom, tags, src_region, country_code, region_id)
             VALUES (:ref, :letter, :kind, :name, ST_SetSRID(ST_MakePoint(:lng, :lat), 4326), :tags::jsonb, :src, :cc, :rid)',
            [
                'ref' => $row['ref'], 'letter' => $row['letter'], 'kind' => $row['kind'], 'name' => $row['name'],
                'lng' => $row['lng'], 'lat' => $row['lat'],
                'tags' => json_encode($row['tags'], \JSON_THROW_ON_ERROR),
                'src' => 'europe/belgium', 'cc' => $row['country_code'], 'rid' => $row['region_id'],
            ],
        );
    }
}
