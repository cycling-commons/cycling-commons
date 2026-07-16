<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Coverage;

use App\Catalog\Entity\Item;
use App\Catalog\ItemState;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Read plane over the pipeline-owned coverage_poi cache
 * (coverage-provider.md §5): drawer detail, name search,
 * town-card nearby and rail counts for the uncurated OSM tier. Raw DBAL like
 * App\Catalog\CatalogProvider — the map read path never hydrates entities.
 *
 * Dedupe rule (osm-data-architecture.md §8): a coverage row is suppressed
 * wherever a served `item` with the same source_ref exists — the object
 * appears once, as curated.
 *
 * @api Consumed by CoverageController.
 */
final class CoverageRepository
{
    /** The serving-cache attribution line (osm-data-architecture.md §4). */
    public const string ATTRIBUTION = '© OpenStreetMap contributors (ODbL)';

    /**
     * Display whitelist for cached OSM tags (design §7): store rich, serve
     * trimmed — the drawer never sees the full filtered tag set.
     */
    public const array TAG_WHITELIST = [
        'opening_hours', 'website', 'contact:website', 'url', 'phone', 'contact:phone',
        'addr:city', 'addr:street', 'addr:housenumber', 'operator', 'description',
        'wheelchair', 'drinking_water', 'fee', 'capacity',
    ];

    /**
     * Coverage POI letters (osm-data-architecture.md §5; A/B/K stay
     * curated-only). Forward-declared: unused until the search/nearby/counts
     * methods land (coverage-provider.md §5).
     *
     * @phpstan-ignore classConstant.unused
     */
    private const string POI_LETTERS_SQL = "('C', 'D', 'E', 'G', 'H', 'I', 'J')";

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Drawer payload for one coverage POI, curated overlay merged (design §7
     * keys: ref, letter, name, kind, ll, tags, curated, attribution). Null
     * when the ref is not in the coverage cache.
     *
     * @return array<string, mixed>|null
     */
    public function detail(string $osmType, int $osmId): ?array
    {
        $ref = $osmType.'/'.$osmId;
        /** @var array{ref: string, letter: string, kind: string|null, name: string|null, lat: string|float, lng: string|float, tags: string, item_id: int|string|null, item_state: string|null, item_name: string|null, item_attributes: string|null}|false $row */
        $row = $this->db->fetchAssociative(
            'SELECT cp.ref, cp.letter, cp.kind, cp.name,
                    ST_Y(cp.geom) AS lat, ST_X(cp.geom) AS lng, cp.tags,
                    i.id AS item_id, i.state AS item_state, i.name AS item_name, i.attributes AS item_attributes
             FROM coverage_poi cp
             LEFT JOIN item i ON i.source_ref = cp.ref AND i.state IN '.ItemState::servedSqlTuple().'
             WHERE cp.ref = :ref
             ORDER BY cp.letter, i.id
             LIMIT 1',
            ['ref' => $ref],
        );
        if (false === $row) {
            return null;
        }

        /** @var array<string, mixed> $tags */
        $tags = json_decode($row['tags'], true, 512, \JSON_THROW_ON_ERROR);

        return [
            'ref' => $row['ref'],
            'letter' => $row['letter'],
            'name' => $row['name'],
            'kind' => $row['kind'],
            'll' => [(float) $row['lat'], (float) $row['lng']],
            // (object) so an empty whitelist intersection still encodes {}.
            'tags' => (object) array_intersect_key($tags, array_flip(self::TAG_WHITELIST)),
            'curated' => null === $row['item_id']
                ? null
                : $this->curatedOverlay((int) $row['item_id'], (string) $row['item_state'], (string) $row['item_name'], (string) $row['item_attributes']),
            'attribution' => self::ATTRIBUTION,
        ];
    }

    /**
     * The curated overlay block: canonical fields (attributes + the name
     * pseudo-field) and public confirmation tallies — the same GROUP BY as
     * ItemConfirmationService::snapshot().
     *
     * @return array{itemId: int, state: string, fields: object, confirmations: object}
     */
    private function curatedOverlay(int $itemId, string $state, string $name, string $attributesJson): array
    {
        /** @var array<string, mixed> $fields */
        $fields = json_decode($attributesJson, true, 512, \JSON_THROW_ON_ERROR);
        if ('' !== $name) {
            $fields[Item::NAME_FIELD] = $name;
        }

        /** @var list<array{stance: string, n: int|string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT stance, COUNT(*) AS n FROM item_confirmation WHERE item_id = :id GROUP BY stance ORDER BY stance',
            ['id' => $itemId],
            ['id' => ParameterType::INTEGER],
        );
        $tallies = [];
        foreach ($rows as $row) {
            $tallies[$row['stance']] = (int) $row['n'];
        }

        return [
            'itemId' => $itemId,
            'state' => $state,
            'fields' => (object) $fields,
            'confirmations' => (object) $tallies,
        ];
    }
}
