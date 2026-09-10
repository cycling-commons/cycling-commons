<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Provider;

use App\Catalog\ConfirmationStance;
use App\Catalog\Entity\Item;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
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

    /**
     * How far an upstream point may move and still be the same CC-row
     * (owner 2026-09-05: 100 m). A register with no stable id keys its rows
     * by coordinates, so any GPS shift is a new key; within this radius the
     * old row is re-keyed rather than duplicated. Beyond it, two taps are two
     * taps.
     */
    public const int MOVE_RADIUS_M = 100;

    public function __construct(
        private readonly Connection $db,
        private readonly ProviderCitations $citations,
    ) {
    }

    /**
     * @param list<array{ref: string, letter: string, name: string, lat: float, lng: float, attributes: array<string, mixed>, country_code?: string|null}> $features
     *
     * @return array{attached: int, inserted: int, updated: int, moved: int, skipped_rider: int, stale: int, contested: int, reclaimed: int}
     */
    public function apply(DataProvider $provider, array $features, \DateTimeImmutable $now = new \DateTimeImmutable()): array
    {
        $counts = ['attached' => 0, 'inserted' => 0, 'updated' => 0, 'moved' => 0, 'skipped_rider' => 0, 'stale' => 0, 'contested' => 0, 'reclaimed' => 0];
        // Every ref this run carries, known up front: a row whose ref is not
        // among them and that sits near a ref nobody has is a point that MOVED.
        $refs = array_map(static fn (array $f): string => $f['ref'], $features);
        $seen = [];

        foreach ($features as $feature) {
            $ref = $feature['ref'];
            $seen[] = $ref;

            if (null !== $this->riderRowAt($feature)) {
                ++$counts['skipped_rider'];
                continue;
            }

            $existing = $this->existingRef($ref);
            $moved = false;
            if (null === $existing) {
                $near = $this->movedRowNear($provider, $feature, $refs);
                if (null !== $near) {
                    // Re-key BEFORE looking for the OSM twin, so the row's own
                    // claim on that node is not read as somebody else's.
                    $this->rekey($near, $ref, $now);
                    $existing = $near;
                    $moved = true;
                }
            }

            $osmRef = $this->osmCounterpart($provider, $feature);
            if (null === $osmRef && $this->hasHeldNeighbour($provider, $feature)) {
                // There WAS a node in range; a nearer record already holds it.
                // Counted apart from a plain miss, because "nothing is mapped
                // here" and "somebody got there first" are different answers
                // and only one of them means the radius is too wide.
                ++$counts['contested'];
            }

            if (null !== $existing) {
                $this->updateRow($existing, $feature, $osmRef, $now);
                ++$counts[$moved ? 'moved' : 'updated'];
                if ($this->reclaim($provider, $existing, $feature)) {
                    ++$counts['reclaimed'];
                }
                continue;
            }

            $this->insertRow($provider, $feature, $osmRef, $now);
            ++$counts['inserted'];
            if (null !== $osmRef) {
                ++$counts['attached'];
            }
        }

        $this->stampSeen($provider, $seen, $now);
        $counts['stale'] = $this->countVanished($provider, $seen);
        $this->stampRegions($provider);

        return $counts;
    }

    /**
     * Every row of this provider gets the region its point falls in, the
     * same smallest-area-wins rule the catalogue import applies
     * (catalog-data-model.md §6). Without it a region scope on the map hides
     * the whole harvest: a served row with no `rid` is out of every region,
     * and the OSM tap it replaced is hidden too, so the rider sees nothing
     * where there used to be a drop. Found on the first RIVM run, 2026-09-05.
     */
    private function stampRegions(DataProvider $provider): void
    {
        $this->db->executeStatement(
            'UPDATE item SET region_id = m.region_id FROM (
                SELECT DISTINCT ON (i.id) i.id AS item_id, r.id AS region_id
                FROM item i JOIN region r ON ST_Contains(r.geom, ST_PointOnSurface(i.geom))
                WHERE i.provider_id = :provider
                ORDER BY i.id, r.area_km2 ASC NULLS LAST, r.id ASC
             ) m WHERE item.id = m.item_id AND item.region_id IS DISTINCT FROM m.region_id',
            ['provider' => $provider->getId()],
        );
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
                               osm_ref, osm_checked_at, attributes, created_at, updated_at, imported_at)
             VALUES (:letter, :name, ST_SetSRID(ST_MakePoint(:lng, :lat), 4326), :cc, :state, :source, :ref, :provider,
                     :osm_ref, :checked, :attrs, :now, :now, :now)',
            [
                'letter' => $feature['letter'],
                'name' => $feature['name'],
                'lat' => $feature['lat'],
                'lng' => $feature['lng'],
                'cc' => $feature['country_code'] ?? $provider->getCountryCode(),
                // Unverified, like every row that enters the catalogue
                // (catalog-data-model.md §5): verification is a rider standing
                // there, never provenance. The register's authority lives in
                // its RANK, not in the state. Written as `verified` until
                // 2026-09-06, which drew 3283 taps nobody here had seen with
                // the plain pin instead of the dashed "?" one (owner).
                'state' => 'unverified',
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
     * This provider's row that the run has not seen, nearest to an upstream
     * point whose ref nobody has, within MOVE_RADIUS_M: the same place with a
     * shifted coordinate, and therefore a shifted key.
     *
     * @param array{ref: string, letter: string, name: string, lat: float, lng: float, attributes: array<string, mixed>, country_code?: string|null} $feature
     * @param list<string>                                                                                                                           $refs
     */
    private function movedRowNear(DataProvider $provider, array $feature, array $refs): ?int
    {
        $id = $this->db->fetchOne(
            'SELECT i.id
               FROM item i
              WHERE i.provider_id = :provider
                AND i.source_ref NOT IN (:refs)
                AND i.geom IS NOT NULL
                AND ST_DWithin(i.geom::geography, ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography, :radius)
              ORDER BY ST_Distance(i.geom::geography, ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography)
              LIMIT 1',
            [
                'provider' => $provider->getId(),
                'refs' => $refs,
                'lat' => $feature['lat'],
                'lng' => $feature['lng'],
                'radius' => self::MOVE_RADIUS_M,
            ],
            ['refs' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );

        return false === $id ? null : (int) $id;
    }

    private function rekey(int $id, string $ref, \DateTimeImmutable $now): void
    {
        $this->db->executeStatement(
            'UPDATE item SET source_ref = :ref, updated_at = :now WHERE id = :id',
            ['id' => $id, 'ref' => $ref, 'now' => $now->format('Y-m-d H:i:s')],
        );
    }

    /**
     * The fields a person has changed on this row, from its history. The
     * register never outranks a rider (§4), so a re-import fills in around
     * those and never over them: a tap a rider moved 50 m stays where the
     * rider put it, and "Not there anymore" stays said.
     *
     * @return list<string>
     */
    private function touchedFields(int $id): array
    {
        /* @var list<string> */
        return $this->db->fetchFirstColumn('SELECT DISTINCT field FROM change_history WHERE item_id = :id', ['id' => $id]);
    }

    /**
     * @param array{ref: string, letter: string, name: string, lat: float, lng: float, attributes: array<string, mixed>, country_code?: string|null} $feature
     */
    private function updateRow(int $id, array $feature, ?string $osmRef, \DateTimeImmutable $now): void
    {
        // Lifecycle state is not touched: a re-import updates facts, never
        // what a curator decided about the row (catalog-data-model.md §8).
        $touched = $this->touchedFields($id);
        /** @var array<string, mixed> $current */
        $current = json_decode((string) $this->db->fetchOne('SELECT attributes FROM item WHERE id = :id', ['id' => $id]), true, 512, \JSON_THROW_ON_ERROR);
        // Upstream fills in; what a person changed, and what a person added
        // that upstream does not carry, stays. Before 2026-09-05 this line
        // replaced the whole object, so the next run would have wiped every
        // rider photo, note and condition on every RIVM tap.
        $attrs = $feature['attributes'];
        foreach ($current as $key => $value) {
            if (!\array_key_exists($key, $attrs) || \in_array($key, $touched, true)) {
                $attrs[$key] = $value;
            }
        }
        $keepName = \in_array(Item::NAME_FIELD, $touched, true);
        $keepGeom = \in_array('location', $touched, true);

        $this->db->executeStatement(
            'UPDATE item
                SET name = '.($keepName ? 'name' : ':name').',
                    geom = '.($keepGeom ? 'geom' : 'ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)').',
                    osm_ref = :osm_ref,
                    osm_checked_at = :checked,
                    attributes = :attrs,
                    updated_at = :now,
                    imported_at = :now
              WHERE id = :id',
            array_filter([
                'id' => $id,
                'name' => $keepName ? null : $feature['name'],
                'lat' => $keepGeom ? null : $feature['lat'],
                'lng' => $keepGeom ? null : $feature['lng'],
                'osm_ref' => $osmRef,
                'checked' => $now->format('Y-m-d H:i:s'),
                'attrs' => json_encode($attrs, \JSON_THROW_ON_ERROR),
                'now' => $now->format('Y-m-d H:i:s'),
            ], static fn (mixed $v, string $k): bool => null !== $v || 'osm_ref' === $k, \ARRAY_FILTER_USE_BOTH),
        );
    }

    /**
     * Custody moves both ways (data-provider-hierarchy.md §6.7.2), and this
     * is the only place it moves back. The provider takes a row the riders
     * verified only when its registry row may, only when it names the
     * attribute carrying its survey date, and only when that survey is newer
     * than our newest vouching confirmation by the provider's margin. The
     * survey date is what gets written, by the provider's own clock, so a
     * confirmation newer than it hands custody straight back. No confirmation
     * is read for anything but its date, and none is ever deleted.
     *
     * @param array{attributes: array<string, mixed>, ...} $feature
     */
    private function reclaim(DataProvider $provider, int $id, array $feature): bool
    {
        $attribute = $provider->getSurveyDateAttribute();
        if (!$provider->mayReclaim() || null === $attribute) {
            return false;
        }
        $survey = self::surveyDate($feature['attributes'][$attribute] ?? null);
        if (null === $survey) {
            return false;
        }
        $vouching = implode(', ', array_map(static fn (ConfirmationStance $s): string => "'".$s->value."'", ConfirmationStance::vouching()));
        /** @var array{state: string, reclaimed: string|null, newest: string|null}|false $row */
        $row = $this->db->fetchAssociative(
            "SELECT i.state, i.custody_reclaimed_at AS reclaimed,
                    (SELECT MAX(c.created_at) FROM item_confirmation c
                      WHERE c.item_id = i.id AND c.source <> 'form' AND c.stance IN ({$vouching})) AS newest
               FROM item i WHERE i.id = :id",
            ['id' => $id],
        );
        if (false === $row || ItemState::Verified->value !== $row['state'] || null === $row['newest']) {
            return false;   // nothing the riders hold: nothing to take back
        }
        $newest = new \DateTimeImmutable($row['newest']);
        if (null !== $row['reclaimed'] && new \DateTimeImmutable($row['reclaimed']) >= $newest) {
            return false;   // already the provider's, until the next confirmation
        }
        if ($survey < $newest->modify(\sprintf('+%d days', $provider->getReclaimMarginDays()))) {
            return false;   // newer, but not by enough to move a pin
        }
        $this->db->executeStatement(
            'UPDATE item SET custody_reclaimed_at = :survey WHERE id = :id',
            ['id' => $id, 'survey' => $survey->format('Y-m-d H:i:s')],
        );

        return true;
    }

    /** A full YYYY-MM-DD, truncated to the day; anything else is not a date. */
    private static function surveyDate(mixed $raw): ?\DateTimeImmutable
    {
        if (!\is_string($raw) || 1 !== preg_match('/^\d{4}-\d{2}-\d{2}/', $raw)) {
            return null;
        }
        try {
            return new \DateTimeImmutable(substr($raw, 0, 10).' 00:00:00');
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Every ref the export carried is a sighting, whatever the loop did with
     * it. Rung 5 is "the publisher still carries this row", not "the publisher
     * changed it" (data-provider-hierarchy.md §6.7.6), so a row the loop left
     * alone because a rider pin sits at the spot advances too. Without this
     * an untouched register entry would age out of rung 5 while the register
     * republishes it every week.
     *
     * @param list<string> $seen
     */
    private function stampSeen(DataProvider $provider, array $seen, \DateTimeImmutable $now): void
    {
        if ([] === $seen) {
            return;
        }

        $this->db->executeStatement(
            'UPDATE item SET imported_at = :now
              WHERE provider_id = :provider AND source_ref IN (:seen)',
            ['now' => $now->format('Y-m-d H:i:s'), 'provider' => $provider->getId(), 'seen' => $seen],
            ['seen' => \Doctrine\DBAL\ArrayParameterType::STRING],
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
