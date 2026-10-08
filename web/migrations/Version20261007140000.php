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
 *    OSM `source_ref`), when the pipeline's table exists;
 * 2. the Type, when one old label is one kind (Museum / culture → museum; the
 *    own kinds keep their labels: Natural feature, Heritage site, Architecture),
 *    or Viewpoint / high point on a Scout row: only the device's VIEW wrote it;
 * 3. the import's `t` label (Castle → castle).
 * A Heritage site whose name says castle ("… Castle", "Castle of …",
 * "Château …", "Castello …") is a castle (owner 2026-10-08): the Wikidata
 * harvest filed its castles as Heritage site.
 * No answer clears the Type: a label naming two OSM kinds (Viewpoint / high
 * point: a viewpoint or a peak; Religious site: a monastery or a place of
 * worship) leaves the decision to a curator.
 *
 * Pending submissions get the same reading of their proposed Type. Decided
 * submissions and change history keep what was said then.
 *
 * The tables are a frozen copy of App\Catalog\PlaceKind on this date.
 */
final class Version20261007140000 extends AbstractMigration
{
    /** Letter → [OSM key, value, kind], in match order. */
    private const array RULES = [
        'P' => [
            ['tourism', 'viewpoint', 'viewpoint'], ['natural', 'peak', 'peak'],
            ['waterway', 'waterfall', 'waterfall'], ['waterway', 'rapids', 'rapids'],
            ['natural', 'cliff', 'cliff'], ['natural', 'cave_entrance', 'cave'],
            ['natural', 'arch', 'arch'], ['natural', 'rock', 'rock'], ['natural', 'stone', 'stone'],
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

    /** A name that says castle, in the Wikidata harvest's English labels. */
    private const string CASTLE_NAME = '/\bcastle\b|^ch[aâ]teau\b|^castello\b/iu';

    /** Kind → the old label `down()` restores. */
    private const array DOWN = [
        'P' => ['viewpoint' => 'Viewpoint / high point', 'nature' => 'Natural feature', '*' => 'Natural feature'],
        'Q' => ['museum' => 'Museum / culture', 'worship' => 'Religious site', 'monument' => 'Monument', 'architecture' => 'Architecture', 'heritage' => 'Heritage site', '*' => 'Heritage site'],
    ];

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
            "SELECT i.id, i.letter, i.name, i.source, i.attributes->>'type' AS type, i.attributes->>'t' AS t, {$tags} AS tags
               FROM item i {$osm} WHERE i.letter IN ('P', 'Q')"
        );
        foreach ($rows as $row) {
            $letter = (string) $row['letter'];
            $tagList = null !== $row['tags'] ? json_decode((string) $row['tags'], true) : [];
            $scoutView = 'scout' === $row['source'] && 'P' === $letter && 'Viewpoint / high point' === $row['type'] ? 'viewpoint' : null;
            $kind = $this->fromTags($letter, \is_array($tagList) ? $tagList : [])
                ?? self::LABELS[$letter][(string) $row['type']] ?? $scoutView ?? self::LABELS[$letter][(string) $row['t']] ?? null;
            if ('heritage' === $kind && 1 === preg_match(self::CASTLE_NAME, (string) $row['name'])) {
                $kind = 'castle';
            }
            if ($kind === $row['type'] || (null === $kind && null === $row['type'])) {
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

        $pending = $this->connection->fetchAllAssociative(
            "SELECT id, letter, payload->>'via' AS via, payload->'details'->>'type' AS type FROM submission
              WHERE letter IN ('P', 'Q') AND status = 'pending' AND payload->'details'->'type' IS NOT NULL"
        );
        foreach ($pending as $row) {
            $kind = self::LABELS[(string) $row['letter']][(string) $row['type']]
                ?? ('scout' === $row['via'] && 'P' === $row['letter'] && 'Viewpoint / high point' === $row['type'] ? 'viewpoint' : null);
            if ($kind === $row['type']) {
                continue;
            }
            $this->addSql(
                null === $kind
                    ? "UPDATE submission SET payload = payload #- '{details,type}', changes = changes - 'type' WHERE id = :id"
                    : "UPDATE submission SET payload = jsonb_set(payload, '{details,type}', to_jsonb(CAST(:kind AS text))),
                              changes = CASE WHEN changes->'type' IS NOT NULL THEN jsonb_set(changes, '{type,now}', to_jsonb(CAST(:kind AS text))) ELSE changes END
                        WHERE id = :id",
                null === $kind ? ['id' => $row['id']] : ['id' => $row['id'], 'kind' => $kind],
            );
        }
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        foreach (self::DOWN as $letter => $labels) {
            foreach ($labels as $kind => $label) {
                if ('*' === $kind) {
                    continue;
                }
                $this->addSql("UPDATE item SET attributes = jsonb_set(attributes, '{type}', to_jsonb(CAST(:label AS text)))
                               WHERE letter = :letter AND attributes->>'type' = :kind", ['label' => $label, 'letter' => $letter, 'kind' => $kind]);
            }
            $this->addSql("UPDATE item SET attributes = jsonb_set(attributes, '{type}', to_jsonb(CAST(:label AS text)))
                           WHERE letter = :letter AND attributes->>'type' = ANY(CAST(:kinds AS text[]))", [
                'label' => $labels['*'], 'letter' => $letter,
                'kinds' => '{'.implode(',', array_diff(array_column(self::RULES[$letter], 2), array_keys($labels))).'}',
            ]);
        }
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
