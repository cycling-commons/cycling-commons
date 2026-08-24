<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog\Import;

use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use Doctrine\DBAL\Connection;

/**
 * Refuses to import a second row for a place the catalog already has.
 *
 * Two rows are the same place when they share a letter, reduce to the same
 * {@see NameKey}, and sit within {@see RADIUS_M} of each other. Name alone is
 * not enough (three "St Mary's Cathedral" rows are three real buildings on
 * three continents) and distance alone is not enough (a cafe and the bike shop
 * next door are two things), so it takes both.
 *
 * Why this exists: dedupe at read time matches **by ref**
 * (coverage-provider.md §5), and a PIVOT row and an OSM row for one hotel have
 * different refs by construction. So a ref-based dedupe structurally cannot see
 * that pair, and five of them were live on the map as two pins on one building
 * (found 2026-08-24). The older, narrower guard this replaces lived in
 * `SeedManualCatalogCommand` alone, matched names as exact strings, and ignored
 * distance entirely.
 *
 * **This class never writes.** It answers a question; the caller decides. That
 * is deliberate: `retired` is a curator decision and never automatic
 * (catalog-data-model.md §4), so an import may decline to ADD a row but may not
 * remove one. Resolving the duplicates already in the database is
 * `app:catalog:dedupe`, which a curator runs and which shows a dry run first.
 *
 * Only served rows count as a collision ({@see ItemState::SERVED}). A retired
 * row is not in anybody's way, which is what lets the two steps compose: once
 * the curator retires a weaker row, the next import admits the better one.
 *
 * @see docs/specs/catalog-data-model.md §5
 *
 * @api
 */
final readonly class DuplicateGuard
{
    /**
     * How close two rows must be to be one place.
     *
     * 250 m, one number for every letter. Measured against the real catalog on
     * 2026-08-24: it covers every cross-source accommodation pair found (worst
     * was 67 m) and both OSM twin pairs (0 m and 27 m), while leaving the two
     * genuinely arguable cases (406 m and 513 m) to a human instead of deciding
     * them silently. A per-letter table would catch a little more and would be
     * a set of numbers nobody could justify one by one.
     */
    public const int RADIUS_M = 250;

    public function __construct(private Connection $db)
    {
    }

    /**
     * The served row that already holds this place, by point.
     *
     * @param string|null $ignoreRef `source:ref` of the row being re-imported,
     *                               so an upsert never collides with itself
     *
     * @return array{id: int, name: string, source: string, source_ref: string, state: string, distance_m: float}|null
     */
    public function existing(string $letter, string $name, float $lat, float $lng, ?string $ignoreRef = null): ?array
    {
        return $this->find(
            $letter, $name, 'ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)',
            ['lat' => $lat, 'lng' => $lng], $ignoreRef,
        );
    }

    /**
     * The same question for a row that arrives as GeoJSON.
     *
     * Measured geometry to geometry, not centroid to centroid: a climb is a
     * LINE, and two lines up the same hill can have midpoints far enough apart
     * to miss each other while overlapping for most of their length.
     *
     * @return array{id: int, name: string, source: string, source_ref: string, state: string, distance_m: float}|null
     */
    public function existingAtGeometry(string $letter, string $name, string $geoJson, ?string $ignoreRef = null): ?array
    {
        return $this->find(
            $letter, $name, 'ST_SetSRID(ST_GeomFromGeoJSON(:geojson), 4326)',
            ['geojson' => $geoJson], $ignoreRef,
        );
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{id: int, name: string, source: string, source_ref: string, state: string, distance_m: float}|null
     */
    private function find(string $letter, string $name, string $geomSql, array $params, ?string $ignoreRef): ?array
    {
        $key = NameKey::of($name);
        if ('' === $key) {
            // A name that reduces to nothing cannot identify anything. Treating
            // it as a key would collide every punctuation-only row with every
            // other one.
            return null;
        }

        // Narrow on geometry first (indexed), then compare keys in PHP: the key
        // is a PHP rule and mirroring it into SQL would be a third copy to keep
        // in step with the cross-language contract.
        /** @var list<array{id: int, name: string, source: string, source_ref: string, state: string, distance_m: string}> $candidates */
        $candidates = $this->db->fetchAllAssociative(
            'SELECT i.id, i.name, i.source, i.source_ref, i.state,
                    ST_Distance(i.geom::geography, '.$geomSql.'::geography) AS distance_m
               FROM item i
              WHERE i.letter = :letter
                AND i.state IN '.ItemState::servedSqlTuple().'
                AND i.geom IS NOT NULL
                AND ST_DWithin(i.geom::geography, '.$geomSql.'::geography, :radius)
              ORDER BY distance_m',
            $params + ['letter' => $letter, 'radius' => self::RADIUS_M],
        );

        foreach ($candidates as $row) {
            if (null !== $ignoreRef && $row['source'].':'.$row['source_ref'] === $ignoreRef) {
                continue;   // the row being re-imported is not its own duplicate
            }
            if (NameKey::of($row['name']) === $key) {
                return [
                    'id' => (int) $row['id'],
                    'name' => $row['name'],
                    'source' => $row['source'],
                    'source_ref' => $row['source_ref'],
                    'state' => $row['state'],
                    'distance_m' => (float) $row['distance_m'],
                ];
            }
        }

        return null;
    }

    /**
     * One line explaining a skip, for the import report.
     *
     * It always says which row is in the way and how far off it sits, because
     * "skipped 4 duplicates" tells an operator nothing they can act on. When
     * the row being held out comes from a BETTER source than the one already
     * there, it says so and names the command that fixes it — otherwise a
     * canonical PIVOT row could stay locked out by an OSM row forever, with the
     * import quietly reporting success every week.
     *
     * @param array{id: int, name: string, source: string, source_ref: string, state: string, distance_m: float} $existing
     */
    public static function explain(string $name, ItemSource $incoming, array $existing): string
    {
        $line = sprintf(
            '%s — already held by #%d "%s" (%s:%s, %s) %d m away',
            $name,
            $existing['id'],
            $existing['name'],
            $existing['source'],
            $existing['source_ref'],
            $existing['state'],
            (int) round($existing['distance_m']),
        );

        $held = ItemSource::tryFrom($existing['source']);
        if (null !== $held && $incoming->dedupeRank() > $held->dedupeRank()) {
            $line .= sprintf(
                ' — NOTE: %s outranks %s; run `app:catalog:dedupe` to retire the weaker row, then re-import',
                $incoming->value,
                $held->value,
            );
        }

        return $line;
    }
}
