<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Moderation;

use App\Catalog\ClaimedOsmRefs;
use App\Catalog\Entity\Item;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What an approved new place replaces: one place, one row.
 *
 * A rider who adds, or corrects, a place that is already on the map in
 * another form (a provider's record of the same tap, the OSM point it was
 * drawn from) should end with ONE row, theirs (owner 2026-09-27: "the one in
 * submission must replace the osm and rivm one"). Two things are replaced
 * when the curator approves:
 *
 * - every served row of the same letter that holds the SAME OpenStreetMap
 *   point as the new place: one node is one thing, so this needs no choice;
 * - every place the wizard listed as similar and the rider left ticked
 *   (`_replaces` in the submission payload: `item:<id>` or an OSM ref),
 *   checked again here: same letter, served, within {@see RADIUS_M}.
 *
 * A replaced row is retired, never deleted (its id is referenced by
 * confirmations, history and moderation rows), the way an accepted duplicate
 * finding retires its loser, and the new place takes over its OSM point when
 * it has none of its own. A ticked OSM point nobody holds yet becomes the new
 * place's own. The curator's approval is the only decision: nothing here runs
 * before it.
 *
 * @see docs/specs/catalog-data-model.md §5a
 *
 * @api
 */
final readonly class ReplacedPlaces
{
    /** The wizard's "similar places" radius; a tick farther away is ignored. */
    public const int RADIUS_M = 250;

    /** At most this many ticks are read from one submission. */
    public const int MAX_TICKS = 8;

    public function __construct(
        private EntityManagerInterface $em,
        private Connection $db,
    ) {
    }

    /**
     * The payload's `_replaces` list, cleaned: `item:<id>` and OSM refs only.
     *
     * @return array{items: list<int>, osm: list<string>}
     */
    public static function ticks(mixed $raw): array
    {
        $items = [];
        $osm = [];
        foreach (\is_array($raw) ? \array_slice(array_values($raw), 0, self::MAX_TICKS) : [] as $tick) {
            if (!\is_string($tick)) {
                continue;
            }
            if (1 === preg_match('~^item:([1-9]\d{0,18})$~', $tick, $m)) {
                $items[] = (int) $m[1];
            } elseif (1 === preg_match('~^(node|way|relation)/[1-9]\d{0,18}$~', $tick)) {
                $osm[] = $tick;
            }
        }

        return ['items' => array_values(array_unique($items)), 'osm' => array_values(array_unique($osm))];
    }

    /**
     * Retire what `$new` replaces and hand it the OSM point.
     *
     * @param array{items: list<int>, osm: list<string>} $ticks
     * @param callable(Item, string, mixed, mixed): void $history records one change on an item
     */
    public function apply(Item $new, array $ticks, callable $history): void
    {
        $newId = (int) $new->getId();

        // A ticked OSM point becomes the new place's own when it has none and
        // no served row holds it; a held one is replaced through its row below.
        if (null === $new->getOsmRef()) {
            foreach ($ticks['osm'] as $ref) {
                if (!$this->isHeld($ref) && $this->osmPointNear($ref, $newId)) {
                    $new->answerOsm($ref);
                    $history($new, 'osm_ref', null, $ref);
                    break;
                }
            }
        }

        foreach ($this->replacedIds($newId, $new->getOsmRef(), $ticks) as $id) {
            $old = $this->em->find(Item::class, $id);
            if (!$old instanceof Item || $id === $newId) {
                continue;
            }
            $was = $old->getState()->value;
            $old->setState(ItemState::Retired);
            $history($old, 'state', $was, ItemState::Retired->value);
            $history($old, 'replaced_by', null, $newId);

            // The survivor inherits the retired row's OSM identity, as an
            // accepted duplicate does: otherwise retiring the row that held
            // the point would bring the raw OSM pin back beside the new place.
            $inherited = $old->getOsmRef();
            if (null === $new->getOsmRef() && null !== $inherited) {
                $new->answerOsm($inherited);
                $history($new, 'osm_ref', null, $inherited);
            }
        }
    }

    /**
     * Served rows of the same letter that are the same place: same OSM point
     * as the new one, or ticked by the rider and within the radius.
     *
     * @param array{items: list<int>, osm: list<string>} $ticks
     *
     * @return list<int>
     */
    private function replacedIds(int $newId, ?string $newOsmRef, array $ticks): array
    {
        $refs = array_values(array_filter(array_merge([$newOsmRef], $ticks['osm'])));
        if ([] === $refs && [] === $ticks['items']) {
            return [];
        }

        $rows = $this->db->fetchFirstColumn(
            'SELECT o.id
               FROM item o, item n
              WHERE n.id = :new
                AND o.id <> n.id
                AND o.letter = n.letter
                AND o.state IN '.ItemState::servedSqlTuple().'
                AND (
                      (o.osm_ref IS NOT NULL AND o.osm_ref IN (:refs))
                   OR (o.source = :osm AND o.source_ref IN (:refs))
                   OR (o.id IN (:ids) AND ST_DWithin(o.geom::geography, n.geom::geography, :radius))
                )
              ORDER BY o.id',
            [
                'new' => $newId,
                'refs' => [] === $refs ? [''] : $refs,
                'ids' => [] === $ticks['items'] ? [0] : $ticks['items'],
                'radius' => self::RADIUS_M,
                'osm' => ItemSource::Osm->value,
            ],
            [
                'refs' => ArrayParameterType::STRING,
                'ids' => ArrayParameterType::INTEGER,
            ],
        );

        return array_map(intval(...), $rows);
    }

    /**
     * What approving would retire, for the curator's card: the same rule as
     * {@see apply()}, and nothing changes.
     *
     * @param array{items: list<int>, osm: list<string>} $ticks
     *
     * @return list<array{id: int, name: string, from: string, provider: ?string, metres: int}>
     */
    public function preview(int $newItemId, ?string $newOsmRef, array $ticks): array
    {
        $ids = $this->replacedIds($newItemId, $newOsmRef, $ticks);
        if ([] === $ids) {
            return [];
        }

        $rows = $this->db->fetchAllAssociative(
            'SELECT o.id, o.name, o.source, p.name AS provider,
                    ST_Distance(o.geom::geography, n.geom::geography) AS metres
               FROM item o
               JOIN item n ON n.id = :new
               LEFT JOIN data_provider p ON p.id = o.provider_id
              WHERE o.id IN (:ids)
              ORDER BY metres',
            ['new' => $newItemId, 'ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        );

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'from' => (string) $r['source'],
            'provider' => null !== $r['provider'] ? (string) $r['provider'] : null,
            'metres' => (int) round((float) $r['metres']),
        ], $rows);
    }

    /**
     * The OpenStreetMap point the new place holds once approved, for the
     * curator's card: the same rule as {@see apply()}, and nothing changes.
     * Its own point; else a ticked point nobody holds; else the point of the
     * first row it retires. The map's free pin for that point is gone after
     * approval, so the card names it beside the rows it retires.
     *
     * @param array{items: list<int>, osm: list<string>} $ticks
     */
    public function takesOsm(int $newItemId, ?string $newOsmRef, array $ticks): ?string
    {
        if (null !== $newOsmRef) {
            return $newOsmRef;
        }
        foreach ($ticks['osm'] as $ref) {
            if (!$this->isHeld($ref) && $this->osmPointNear($ref, $newItemId)) {
                return $ref;
            }
        }
        $ids = $this->replacedIds($newItemId, null, $ticks);
        if ([] === $ids) {
            return null;
        }
        $ref = $this->db->fetchOne(
            'SELECT osm_ref FROM item WHERE id IN (:ids) AND osm_ref IS NOT NULL ORDER BY id LIMIT 1',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        );

        return false === $ref ? null : (string) $ref;
    }

    /** True when a served row already holds this OSM point. */
    private function isHeld(string $ref): bool
    {
        return false !== $this->db->fetchOne(
            'SELECT 1 FROM ('.ClaimedOsmRefs::selectSql().') held WHERE held.ref = :ref LIMIT 1',
            ['ref' => $ref],
        );
    }

    /** The OSM point is one of the new place's letter and within the radius. */
    private function osmPointNear(string $ref, int $newItemId): bool
    {
        return false !== $this->db->fetchOne(
            'SELECT 1 FROM coverage_poi cp, item n
              WHERE n.id = :new AND cp.ref = :ref AND cp.letter = n.letter
                AND ST_DWithin(cp.geom::geography, n.geom::geography, :radius)
              LIMIT 1',
            ['new' => $newItemId, 'ref' => $ref, 'radius' => self::RADIUS_M],
        );
    }
}
