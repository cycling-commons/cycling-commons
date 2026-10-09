<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P and Q Types become kinds (docs/specs/osm-data-architecture.md §5a): every
 * OSM tag we show is one kind, and an own kind has no OSM tag.
 *
 * Per item, the first answer wins:
 * 1. the linked OSM point's tag (`coverage_poi`, joined on `osm_ref`, else an
 *    OSM `source_ref`), when the pipeline's table exists; a harvested kind
 *    before one the harvest leaves out, as the pipeline reads it;
 * 2. the Type, when it is a kind already, or one old label is one kind
 *    (Museum / culture → museum; the own kinds keep their labels: Natural
 *    feature, Heritage site, Architecture), or Viewpoint / high point on a
 *    Scout row: only the device's VIEW wrote it;
 * 3. the import's `t` label (Castle → castle).
 * Heritage site on a `wikidata` row is a castle: Q23413 (castle) was the only
 * Wikidata class the harvest (tools/wikimedia/country_places.py) ever filed
 * under it. A person's own change to that Type keeps Heritage site.
 * No answer clears the Type: a label naming two OSM kinds (Viewpoint / high
 * point: a viewpoint or a peak; Religious site: a monastery or a place of
 * worship) leaves the decision to a curator. A second run changes nothing.
 *
 * Submissions a curator can still approve (pending, needs info, and those in
 * Trash from either) get the same reading of their proposed Type, in the
 * payload and in `changes`, which an approval applies. Decided submissions and
 * change history keep what was said then.
 *
 * The tables are a frozen copy of App\Catalog\PlaceKind on this date.
 */
final class Version20261007140000 extends AbstractMigration
{
    /** Letter → [OSM key, value, kind], in match order: harvested kinds first. */
    private const array RULES = [
        'P' => [
            ['tourism', 'viewpoint', 'viewpoint'],
            ['waterway', 'waterfall', 'waterfall'], ['waterway', 'rapids', 'rapids'],
            ['natural', 'cliff', 'cliff'], ['natural', 'cave_entrance', 'cave'],
            ['natural', 'arch', 'arch'], ['natural', 'rock', 'rock'], ['natural', 'stone', 'stone'],
            ['natural', 'peak', 'peak'],
        ],
        'Q' => [
            ['historic', 'castle', 'castle'], ['historic', 'fort', 'fort'], ['historic', 'ruins', 'ruins'],
            ['historic', 'monument', 'monument'], ['historic', 'memorial', 'memorial'],
            ['historic', 'archaeological_site', 'archaeological'], ['historic', 'manor', 'manor'],
            ['historic', 'monastery', 'monastery'], ['tourism', 'museum', 'museum'],
            ['amenity', 'place_of_worship', 'worship'],
        ],
    ];

    /** Letter → English label → kind: the kinds' own labels and the exact older Types. */
    private const array LABELS = [
        'P' => [
            'Viewpoint' => 'viewpoint', 'Peak' => 'peak', 'Waterfall' => 'waterfall', 'Rapids' => 'rapids',
            'Cliff' => 'cliff', 'Cave entrance' => 'cave', 'Rock arch' => 'arch', 'Rock' => 'rock', 'Boulder' => 'stone',
            'Natural feature' => 'nature',
        ],
        'Q' => [
            'Castle' => 'castle', 'Fort' => 'fort', 'Ruins' => 'ruins', 'Monument' => 'monument',
            'Memorial' => 'memorial', 'Archaeological site' => 'archaeological', 'Manor' => 'manor',
            'Monastery' => 'monastery', 'Museum' => 'museum', 'Place of worship' => 'worship',
            'Museum / culture' => 'museum', 'Heritage site' => 'heritage', 'Architecture' => 'architecture',
        ],
    ];

    /** The Wikidata harvest's Heritage site: its one class, Q23413, is a castle. */
    private const string WIKIDATA_HERITAGE = 'Heritage site';

    /** Submissions a curator can still approve, directly or once restored from Trash. */
    private const string OPEN_SUBMISSION_SQL = "(status IN ('pending', 'needs_info') OR (status = 'trashed' AND trashed_from IN ('pending', 'needs_info')))";

    #[\Override]
    public function getDescription(): string
    {
        return 'P/Q Types become kinds, one OSM tag each';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $withOsm = null !== $this->connection->fetchOne("SELECT to_regclass('public.coverage_poi')");
        $osm = $withOsm
            ? 'LEFT JOIN coverage_poi cp ON cp.letter = i.letter AND cp.ref = COALESCE(i.osm_ref, CASE WHEN i.source_ref ~ \'^(node|way|relation)/\' THEN i.source_ref END)'
            : '';
        $tags = $withOsm ? 'cp.tags::text' : 'NULL';
        $rows = $this->connection->fetchAllAssociative(
            "SELECT i.id, i.letter, i.source, i.attributes->>'type' AS type, i.attributes->>'t' AS t, {$tags} AS tags,
                    EXISTS (SELECT 1 FROM change_history h WHERE h.item_id = i.id AND h.field = 'type' AND h.changed_by <> 0) AS type_chosen
               FROM item i {$osm} WHERE i.letter IN ('P', 'Q')"
        );
        foreach ($rows as $row) {
            $letter = (string) $row['letter'];
            $type = null !== $row['type'] ? (string) $row['type'] : null;
            $tagList = null !== $row['tags'] ? json_decode((string) $row['tags'], true) : [];
            $castle = 'wikidata' === $row['source'] && self::WIKIDATA_HERITAGE === $type && !$row['type_chosen'] ? 'castle' : null;
            $kind = $this->fromTags($letter, \is_array($tagList) ? $tagList : [])
                ?? $this->fromType($letter, $type, 'scout' === $row['source'], $castle)
                ?? self::LABELS[$letter][(string) $row['t']] ?? null;
            if ($kind === $type) {
                continue;
            }
            $this->addSql(
                null === $kind
                    ? "UPDATE item SET attributes = attributes - 'type' WHERE id = :id"
                    // An empty attribute set can be stored as `[]`: a path is only set inside an object.
                    : "UPDATE item SET attributes = jsonb_set(CASE WHEN jsonb_typeof(attributes) = 'object' THEN attributes ELSE '{}'::jsonb END, '{type}', to_jsonb(CAST(:kind AS text))) WHERE id = :id",
                null === $kind ? ['id' => $row['id']] : ['id' => $row['id'], 'kind' => $kind],
            );
        }

        $open = $this->connection->fetchAllAssociative(
            "SELECT id, letter, payload->>'via' AS via, COALESCE(payload->'details'->>'type', changes->'type'->>'now') AS type FROM submission
              WHERE letter IN ('P', 'Q') AND ".self::OPEN_SUBMISSION_SQL."
                AND (payload->'details'->'type' IS NOT NULL OR changes->'type'->'now' IS NOT NULL)"
        );
        foreach ($open as $row) {
            $type = null !== $row['type'] ? (string) $row['type'] : null;
            $kind = $this->fromType((string) $row['letter'], $type, 'scout' === $row['via'], null);
            if ($kind === $type) {
                continue;
            }
            $this->addSql(
                null === $kind
                    ? "UPDATE submission SET payload = payload #- '{details,type}', changes = changes - 'type' WHERE id = :id"
                    : "UPDATE submission SET payload = CASE WHEN payload->'details'->'type' IS NOT NULL THEN jsonb_set(payload, '{details,type}', to_jsonb(CAST(:kind AS text))) ELSE payload END,
                              changes = CASE WHEN changes->'type'->'now' IS NOT NULL THEN jsonb_set(changes, '{type,now}', to_jsonb(CAST(:kind AS text))) ELSE changes END
                        WHERE id = :id",
                null === $kind ? ['id' => $row['id']] : ['id' => $row['id'], 'kind' => $kind],
            );
        }
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'P/Q kinds cannot become the old Types again: several labels read as one kind, broad labels were cleared, and submissions were rewritten. Restore a backup taken before this migration.'
        );
    }

    /**
     * The kind a stored or proposed Type says: a kind is its own answer, then
     * the Wikidata castle, an exact label, or a Scout viewpoint.
     */
    private function fromType(string $letter, ?string $type, bool $scout, ?string $castle): ?string
    {
        if (null === $type) {
            return null;
        }
        if (\in_array($type, self::LABELS[$letter], true)) {
            return $type;
        }

        return $castle
            ?? self::LABELS[$letter][$type]
            ?? ($scout && 'P' === $letter && 'Viewpoint / high point' === $type ? 'viewpoint' : null);
    }

    /** @param array<string, mixed> $tags */
    private function fromTags(string $letter, array $tags): ?string
    {
        foreach (self::RULES[$letter] as [$key, $value, $kind]) {
            if (($tags[$key] ?? null) === $value) {
                return $kind;
            }
        }

        return null;
    }
}
