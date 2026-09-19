<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

use App\Moderation\ModerationScope;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Reads for the curator data desk.
 *
 * Scope is applied through the ITEM, never through a column on the finding:
 * `item.region_id` and `item.country_code` are recomputed from geometry on
 * every import, so a copy on the finding row would go stale the first time a
 * boundary moved, and a curator would start seeing another country's work.
 *
 * @see docs/specs/catalog-data-model.md §5c
 *
 * @api
 */
final readonly class CatalogFindingRepository
{
    public function __construct(private Connection $db)
    {
    }

    /**
     * Open findings a curator may act on, newest first. `$country` (ISO2) and
     * `$region` (region name) narrow the list inside the scope, like the
     * submissions queue's filters (moderation-and-contribution.md §5.2).
     *
     * @return list<array<string, mixed>>
     */
    public function open(ModerationScope $scope, ?FindingKind $kind = null, ?string $country = null, ?string $region = null, int $limit = 200): array
    {
        $params = ['status' => FindingStatus::Open->value];
        $types = [];
        $sql = 'SELECT f.id, f.kind, f.osm_ref, f.detail, f.created_at,
                       i.id   AS item_id,   i.name   AS item_name,   i.letter,
                       i.source AS item_source, i.country_code, i.region_id,
                       r.id   AS rel_id,    r.name   AS rel_name,    r.source AS rel_source
                  FROM catalog_finding f
                  JOIN item i ON i.id = f.item_id
             LEFT JOIN item r ON r.id = f.related_item_id
                 WHERE f.status = :status';

        if (null !== $kind) {
            $sql .= ' AND f.kind = :kind';
            $params['kind'] = $kind->value;
        }
        if (null !== $country && '' !== $country) {
            $sql .= ' AND i.country_code = :country';
            $params['country'] = $country;
        }
        if (null !== $region && '' !== $region) {
            $sql .= ' AND EXISTS (SELECT 1 FROM region fr WHERE fr.id = i.region_id AND fr.name = :region)';
            $params['region'] = $region;
        }
        $sql .= $this->scopeArm($scope, $params, $types);

        $rows = $this->db->fetchAllAssociative(
            $sql.' ORDER BY f.kind, f.id DESC LIMIT '.max(1, $limit),
            $params,
            $types,
        );

        // Decode here, not in the template: a Twig filter that parses JSON
        // would put a data-shape decision in the view, and every other desk
        // reads arrays.
        foreach ($rows as $i => $row) {
            $rows[$i]['detail'] = \is_string($row['detail'])
                ? (array) json_decode($row['detail'], true, 512, \JSON_THROW_ON_ERROR)
                : [];
        }

        return $rows;
    }

    /**
     * One finding with both rows' coordinates, for the map resolve view.
     *
     * Coordinates come from the item geometry rather than the finding, because
     * a curator moving a pin must change what the map shows next time.
     *
     * @return array{id: int, kind: string, items: list<array<string, mixed>>}|null
     */
    public function detail(int $id, ModerationScope $scope): ?array
    {
        if (!$this->isInScope($id, $scope)) {
            return null;
        }

        /** @var array{id: int, kind: string, item_id: int, related_item_id: int|null}|false $row */
        $row = $this->db->fetchAssociative(
            'SELECT id, kind, item_id, related_item_id FROM catalog_finding WHERE id = :id AND status = :status',
            ['id' => $id, 'status' => FindingStatus::Open->value],
        );
        if (false === $row) {
            return null;
        }

        $ids = array_values(array_filter([(int) $row['item_id'], $row['related_item_id']]));

        $items = $this->db->fetchAllAssociative(
            'SELECT i.id, i.name, i.letter, i.source, i.state,
                    ST_Y(ST_Centroid(i.geom)) AS lat, ST_X(ST_Centroid(i.geom)) AS lng
               FROM item i WHERE i.id IN (:ids)',
            ['ids' => array_map(intval(...), $ids)],
            ['ids' => ArrayParameterType::INTEGER],
        );

        // The row the scan proposed retiring is first, so the map can label
        // them in the same order the desk did.
        usort($items, static fn (array $a, array $b): int => array_search((int) $a['id'], $ids, true) <=> array_search((int) $b['id'], $ids, true));

        return ['id' => (int) $row['id'], 'kind' => (string) $row['kind'], 'items' => $items];
    }

    /**
     * Countries with an open finding in this curator's scope, for the filter.
     *
     * @return list<string>
     */
    public function countries(ModerationScope $scope): array
    {
        $params = ['status' => FindingStatus::Open->value];
        $types = [];
        $sql = "SELECT DISTINCT i.country_code FROM catalog_finding f JOIN item i ON i.id = f.item_id
                 WHERE f.status = :status AND i.country_code <> ''";

        return $this->sorted($this->db->fetchFirstColumn($sql.$this->scopeArm($scope, $params, $types), $params, $types));
    }

    /**
     * Region names with an open finding in this curator's scope, for the filter.
     *
     * @return list<string>
     */
    public function regions(ModerationScope $scope): array
    {
        $params = ['status' => FindingStatus::Open->value];
        $types = [];
        $sql = 'SELECT DISTINCT fr.name FROM catalog_finding f JOIN item i ON i.id = f.item_id
                  JOIN region fr ON fr.id = i.region_id
                 WHERE f.status = :status';

        return $this->sorted($this->db->fetchFirstColumn($sql.$this->scopeArm($scope, $params, $types), $params, $types));
    }

    /** How many open findings this curator is being asked about (the tab badge). */
    public function openCount(ModerationScope $scope): int
    {
        $params = ['status' => FindingStatus::Open->value];
        $types = [];
        $sql = 'SELECT COUNT(*) FROM catalog_finding f JOIN item i ON i.id = f.item_id
                 WHERE f.status = :status';

        return (int) $this->db->fetchOne($sql.$this->scopeArm($scope, $params, $types), $params, $types);
    }

    /**
     * True when this finding is inside the curator's area.
     *
     * Asked again on every write. The list is filtered, but a filtered list is
     * not an access check: a curator can post an id they were never shown.
     */
    public function isInScope(int $findingId, ModerationScope $scope): bool
    {
        $params = ['id' => $findingId];
        $types = [];
        $sql = 'SELECT 1 FROM catalog_finding f JOIN item i ON i.id = f.item_id WHERE f.id = :id';

        return (bool) $this->db->fetchOne($sql.$this->scopeArm($scope, $params, $types).' LIMIT 1', $params, $types);
    }

    /**
     * @param array<string, mixed>              $params
     * @param array<string, ArrayParameterType> $types
     */
    private function scopeArm(ModerationScope $scope, array &$params, array &$types): string
    {
        if ($scope->global) {
            return '';
        }
        if ([] === $scope->regionIds && [] === $scope->countryCodes) {
            // A curator with no area sees nothing. Returning everything would
            // be the wrong way for this to fail.
            return ' AND FALSE';
        }

        $arms = [];
        if ([] !== $scope->regionIds) {
            $arms[] = 'i.region_id IN (:rids)';
            $params['rids'] = $scope->regionIds;
            $types['rids'] = ArrayParameterType::INTEGER;
        }
        if ([] !== $scope->countryCodes) {
            $arms[] = 'i.country_code IN (:ccs)';
            $params['ccs'] = $scope->countryCodes;
            $types['ccs'] = ArrayParameterType::STRING;
        }

        return ' AND ('.implode(' OR ', $arms).')';
    }

    /**
     * @param list<mixed> $values
     *
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        $strings = array_map(strval(...), $values);
        (new \Collator('en'))->sort($strings);

        return array_values($strings);
    }
}
