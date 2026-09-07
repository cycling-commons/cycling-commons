<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Api\V1;

use App\Api\V1\Dto\ItemFeature;
use App\Catalog\CoverageRetirement;
use App\Catalog\GoneRows;
use App\Catalog\ItemState;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * /v1/search read path (docs/specs/public-api.md §2.2). Must not join
 * contributor identity (docs/specs/public-api-personal-data-boundary.md).
 *
 * @api
 */
final class PublicItemsProvider
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param string|null                                   $letter one catalogue letter, or null for all letters
     * @param array{0: float, 1: float, 2: float, 3: float} $bbox   minLon,minLat,maxLon,maxLat (WGS84)
     * @param 'community'|'curated'|null                    $tier   null serves both tiers
     *
     * @return list<ItemFeature>
     */
    public function featuresInBbox(?string $letter, array $bbox, int $limit, ?string $tier = null): array
    {
        // Named once so SELECT and the tier filter cannot disagree; no contributor join.
        $verified = '(i.state = \'verified\' OR i.source = \'authority\' OR EXISTS (SELECT 1 FROM item_confirmation c WHERE c.item_id = i.id AND c.source <> \'form\'))';

        $sql = 'SELECT i.id, i.name, i.letter, ST_AsGeoJSON(i.geom) AS geom, '.$verified.' AS verified
                FROM item i
                WHERE i.state IN '.ItemState::servedSqlTuple().'
                  AND i.geom && ST_MakeEnvelope(:minLon, :minLat, :maxLon, :maxLat, 4326)';
        $params = [
            'minLon' => $bbox[0],
            'minLat' => $bbox[1],
            'maxLon' => $bbox[2],
            'maxLat' => $bbox[3],
            'lim' => $limit,
        ];

        if (null !== $letter) {
            $sql .= ' AND i.letter = :letter';
            $params['letter'] = $letter;
            if (\in_array($letter, CoverageRetirement::LETTERS, true)) {
                $sql .= ' AND NOT ('.CoverageRetirement::untouchedOsmSql('i').')';
            }
        } else {
            // Retirement exclusion stays scoped to coverage letters.
            $sql .= ' AND NOT (i.letter IN '.CoverageRetirement::lettersSqlTuple().' AND ('.CoverageRetirement::untouchedOsmSql('i').'))';
        }
        if ('curated' === $tier) {
            $sql .= ' AND '.$verified;
        } elseif ('community' === $tier) {
            $sql .= ' AND NOT '.$verified;
        }
        $sql .= ' AND '.GoneRows::notGoneSql('i').'
                  ORDER BY i.id
                  LIMIT :lim';

        $rows = $this->db->fetchAllAssociative($sql, $params, ['lim' => ParameterType::INTEGER]);

        $features = [];
        foreach ($rows as $row) {
            /** @var array<string, mixed>|null $geometry */
            $geometry = json_decode((string) $row['geom'], true);
            if (!\is_array($geometry)) {
                continue;   // a row with unparseable geometry is not a feature
            }
            $features[] = new ItemFeature(
                (int) $row['id'],
                (string) $row['letter'],
                (string) $row['name'],
                $row['verified'] ? 'curated' : 'community',
                $geometry,
            );
        }

        return $features;
    }
}
