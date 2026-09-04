<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Provider;

use App\Catalog\ItemSource;
use App\Provider\Entity\DataProvider;
use Doctrine\DBAL\Connection;

/**
 * Match, then attach or insert: one authority's records into the catalogue.
 *
 * The fetching and the reprojecting are Python's ({@see pipeline/providers}),
 * because reading a geospatial service is squarely on that side of the
 * boundary. What arrives here is already normalised: WGS84 points, our
 * attribute names, one stable ref each. This is the tabular half, and it is
 * three decisions per feature.
 *
 * **Match.** Look for an OpenStreetMap counterpart within the provider's own
 * `match_radius_m`, restricted to the letter AND, where the provider says so,
 * to tags that mean the same kind of thing. A letter is not a kind: letter B
 * holds 7024 rows in the Netherlands and only 2744 of them are taps, so
 * matching by letter alone tied a public tap to the café across the road.
 * Found means this record and that node are one real place.
 *
 * **Attach or insert.** A match writes the item with `osm_ref` set to that
 * node, and the existing suppression does the rest: `CoverageRepository`
 * already hides a coverage POI whose ref is claimed by a served item, so the
 * raw pin disappears rather than sitting beside the authority's. No match
 * writes the item with `osm_ref` NULL and `osm_checked_at` set, which is the
 * tri-state "we looked and there is nothing" rather than "nobody looked".
 *
 * **One node, one claim.** An OSM node may be claimed by at most one item. Two
 * taps 30 m apart are both within 50 m of the same node, and letting both
 * attach would point two rows at one node: the suppression that hides the raw
 * pin assumes a single claimant, and the second row would be a duplicate
 * carrying somebody else's identity. The nearest feature takes the node; the
 * next one inserts unattached, which says "a real place we could not tie to a
 * node" rather than a wrong tie. Measured on the Dutch register: 10 nodes out
 * of 2515 were contested by exactly two taps each.
 *
 * **Never touch a rider row.** If the place is already held by `manual`,
 * `user` or `scout`, the authority record is dropped for that place. A curator
 * cannot give a provider a rank that outranks a rider's own contribution, and
 * neither can a harvest.
 *
 * **A vanished feature is not a deletion.** A row the upstream no longer
 * carries is left exactly where it is and counted as stale, because "the
 * publisher dropped it" and "the publisher's export broke" look identical from
 * here, and one of those must not silently empty a catalogue.
 *
 * @see docs/specs/data-provider-hierarchy.md §5
 *
 * @api
 */
final class ProviderHarvest
{
    /** Sources a harvest may never displace (data-provider-hierarchy.md §4). */
    private const array RIDER_SOURCES = ['manual', 'user', 'scout'];

    public function __construct(
        private readonly Connection $db,
        private readonly ProviderCitations $citations,
    ) {
    }

    /**
     * @param list<array{ref: string, letter: string, name: string, lat: float, lng: float, attributes: array<string, mixed>, country_code?: string|null}> $features
     *
     * @return array{attached: int, inserted: int, updated: int, skipped_rider: int, stale: int, contested: int}
     */
    public function apply(DataProvider $provider, array $features, \DateTimeImmutable $now = new \DateTimeImmutable()): array
    {
        $counts = ['attached' => 0, 'inserted' => 0, 'updated' => 0, 'skipped_rider' => 0, 'stale' => 0, 'contested' => 0];
        $seen = [];

        foreach ($features as $feature) {
            $ref = $feature['ref'];
            $seen[] = $ref;

            if (null !== $this->riderRowAt($feature)) {
                ++$counts['skipped_rider'];
                continue;
            }

            $osmRef = $this->osmCounterpart($provider, $feature);
            if (null === $osmRef && $this->hasHeldNeighbour($provider, $feature)) {
                // There WAS a node in range; a nearer record already holds it.
                // Counted apart from a plain miss, because "nothing is mapped
                // here" and "somebody got there first" are different answers
                // and only one of them means the radius is too wide.
                ++$counts['contested'];
            }
            $existing = $this->existingRef($ref);

            if (null !== $existing) {
                $this->updateRow($existing, $feature, $osmRef, $now);
                ++$counts['updated'];
                continue;
            }

            $this->insertRow($provider, $feature, $osmRef, $now);
            ++$counts['inserted'];
            if (null !== $osmRef) {
                ++$counts['attached'];
            }
        }

        $counts['stale'] = $this->countVanished($provider, $seen);

        return $counts;
    }

    /**
     * The rider row holding this place, if one does.
     *
     * Matched by distance alone rather than by name: a rider naming a tap
     * "Fontein" and a register naming it "Openbare drinkwaterkraan" are still
     * one tap, and the harvest must not add the second beside the first.
     *
     * @param array{ref: string, letter: string, name: string, lat: float, lng: float, attributes: array<string, mixed>, country_code?: string|null} $feature
     */
    private function riderRowAt(array $feature): ?int
    {
        $id = $this->db->fetchOne(
            'SELECT i.id
               FROM item i
              WHERE i.letter = :letter
                AND i.source IN (:sources)
                AND i.geom IS NOT NULL
                AND ST_DWithin(i.geom::geography, ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography, :radius)
              ORDER BY ST_Distance(i.geom::geography, ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography)
              LIMIT 1',
            [
                'letter' => $feature['letter'],
                'sources' => self::RIDER_SOURCES,
                'lat' => $feature['lat'],
                'lng' => $feature['lng'],
                'radius' => 50,
            ],
            ['sources' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );

        return false === $id ? null : (int) $id;
    }

    /**
     * The OSM node this record is a record OF, within the provider's radius.
     *
     * @param array{ref: string, letter: string, name: string, lat: float, lng: float, attributes: array<string, mixed>, country_code?: string|null} $feature
     */
    private function osmCounterpart(DataProvider $provider, array $feature): ?string
    {
        [$tagSql, $tagParams] = $this->tagFilter($provider);

        $ref = $this->db->fetchOne(
            'SELECT cp.ref
               FROM coverage_poi cp
              WHERE cp.letter = :letter
                AND ST_DWithin(cp.geom::geography, ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography, :radius)
                -- Not a node some other row already IS. `osm_ref` is the
                -- identity spine, and two items claiming one node is two rows
                -- claiming to be the same thing.
                AND NOT EXISTS (
                    SELECT 1 FROM item held
                     WHERE held.osm_ref = cp.ref AND held.source_ref <> :self
                )
                '.$tagSql.'
              ORDER BY ST_Distance(cp.geom::geography, ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography)
              LIMIT 1',
            [
                'letter' => $feature['letter'],
                'lat' => $feature['lat'],
                'lng' => $feature['lng'],
                'radius' => $provider->getMatchRadiusM(),
                'self' => $feature['ref'],
            ] + $tagParams,
        );

        return false === $ref ? null : (string) $ref;
    }

    /**
     * True when a node was in range but already spoken for.
     *
     * Only asked when the first query found nothing free, so the common case
     * still costs one query.
     *
     * @param array{ref: string, letter: string, name: string, lat: float, lng: float, attributes: array<string, mixed>, country_code?: string|null} $feature
     */
    private function hasHeldNeighbour(DataProvider $provider, array $feature): bool
    {
        [$tagSql, $tagParams] = $this->tagFilter($provider);

        return false !== $this->db->fetchOne(
            'SELECT 1
               FROM coverage_poi cp
              WHERE cp.letter = :letter
                AND ST_DWithin(cp.geom::geography, ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography, :radius)
                '.$tagSql.'
              LIMIT 1',
            [
                'letter' => $feature['letter'],
                'lat' => $feature['lat'],
                'lng' => $feature['lng'],
                'radius' => $provider->getMatchRadiusM(),
            ] + $tagParams,
        );
    }

    /**
     * The tag test, as SQL and its parameters.
     *
     * Empty when the provider names no tags, which keeps the letter-wide
     * match for a dataset whose letter really is its kind.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function tagFilter(DataProvider $provider): array
    {
        $tags = $provider->getMatchTags() ?? [];
        if ([] === $tags) {
            return ['', []];
        }

        $clauses = [];
        $params = [];
        $i = 0;
        foreach ($tags as $key => $values) {
            foreach ($values as $value) {
                $name = 'tag'.$i++;
                // `->>` rather than `@>`: the value is a plain string, and the
                // containment operator would want a JSON document built per row.
                $clauses[] = 'cp.tags->>'.$this->db->quote($key).' = :'.$name;
                $params[$name] = $value;
            }
        }

        return [[] === $clauses ? '' : 'AND ('.implode(' OR ', $clauses).')', $params];
    }

    private function existingRef(string $ref): ?int
    {
        $id = $this->db->fetchOne(
            "SELECT id FROM item WHERE source = 'authority' AND source_ref = :ref",
            ['ref' => $ref],
        );

        return false === $id ? null : (int) $id;
    }

    /**
     * @param array{ref: string, letter: string, name: string, lat: float, lng: float, attributes: array<string, mixed>, country_code?: string|null} $feature
     */
    private function insertRow(DataProvider $provider, array $feature, ?string $osmRef, \DateTimeImmutable $now): void
    {
        $this->db->executeStatement(
            'INSERT INTO item (letter, name, geom, country_code, state, source, source_ref, provider_id,
                               osm_ref, osm_checked_at, attributes, created_at, updated_at)
             VALUES (:letter, :name, ST_SetSRID(ST_MakePoint(:lng, :lat), 4326), :cc, :state, :source, :ref, :provider,
                     :osm_ref, :checked, :attrs, :now, :now)',
            [
                'letter' => $feature['letter'],
                'name' => $feature['name'],
                'lat' => $feature['lat'],
                'lng' => $feature['lng'],
                'cc' => $feature['country_code'] ?? $provider->getCountryCode(),
                // An authority record is verified by its provenance, which is
                // the whole reason the rank exists (catalog-data-model.md §5).
                'state' => 'verified',
                'source' => ItemSource::Authority->value,
                'ref' => $feature['ref'],
                'provider' => $provider->getId(),
                'osm_ref' => $osmRef,
                // Set whichever way the answer went: NULL osm_ref WITH a
                // timestamp is "we looked and there is nothing", which must
                // never be retried as though it were a gap.
                'checked' => $now->format('Y-m-d H:i:s'),
                'attrs' => json_encode($feature['attributes'], \JSON_THROW_ON_ERROR),
                'now' => $now->format('Y-m-d H:i:s'),
            ],
        );
    }

    /**
     * @param array{ref: string, letter: string, name: string, lat: float, lng: float, attributes: array<string, mixed>, country_code?: string|null} $feature
     */
    private function updateRow(int $id, array $feature, ?string $osmRef, \DateTimeImmutable $now): void
    {
        // Lifecycle state is not touched: a re-import updates facts, never
        // what a curator decided about the row (catalog-data-model.md §8).
        $this->db->executeStatement(
            'UPDATE item
                SET name = :name,
                    geom = ST_SetSRID(ST_MakePoint(:lng, :lat), 4326),
                    osm_ref = :osm_ref,
                    osm_checked_at = :checked,
                    attributes = :attrs,
                    updated_at = :now
              WHERE id = :id',
            [
                'id' => $id,
                'name' => $feature['name'],
                'lat' => $feature['lat'],
                'lng' => $feature['lng'],
                'osm_ref' => $osmRef,
                'checked' => $now->format('Y-m-d H:i:s'),
                'attrs' => json_encode($feature['attributes'], \JSON_THROW_ON_ERROR),
                'now' => $now->format('Y-m-d H:i:s'),
            ],
        );
    }

    /**
     * How many of this provider's rows the upstream no longer carries.
     *
     * Counted, never deleted. The desk raises the number; a person decides
     * whether a publisher dropped a hundred taps or a publisher's export
     * broke.
     *
     * @param list<string> $seen
     */
    private function countVanished(DataProvider $provider, array $seen): int
    {
        if ([] === $seen) {
            // An empty harvest is a broken fetch, not a publisher who deleted
            // everything. Calling every row stale here would raise an alarm
            // about the data when the alarm belongs on the fetch.
            return 0;
        }

        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM item
              WHERE provider_id = :provider AND source_ref NOT IN (:seen)',
            ['provider' => $provider->getId(), 'seen' => $seen],
            ['seen' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
    }

    public function invalidateCitations(): void
    {
        $this->citations->invalidate();
    }
}
