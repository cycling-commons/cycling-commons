<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

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
     * Open findings a curator may act on, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function open(ModerationScope $scope, ?FindingKind $kind = null, int $limit = 200): array
    {
        $params = ['status' => FindingStatus::Open->value];
        $types = [];
        $sql = "SELECT f.id, f.kind, f.osm_ref, f.detail, f.created_at,
                       i.id   AS item_id,   i.name   AS item_name,   i.letter,
                       i.source AS item_source, i.country_code, i.region_id,
                       r.id   AS rel_id,    r.name   AS rel_name,    r.source AS rel_source
                  FROM catalog_finding f
                  JOIN item i ON i.id = f.item_id
             LEFT JOIN item r ON r.id = f.related_item_id
                 WHERE f.status = :status";

        if (null !== $kind) {
            $sql .= ' AND f.kind = :kind';
            $params['kind'] = $kind->value;
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
}
