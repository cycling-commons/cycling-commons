<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Messaging;

use App\Catalog\OperationalRegions;
use App\Contribution\PlaceText;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Where an approved-submission message takes its reader, keyed by submission
 * id, read from the submission row itself:
 *
 * - a submission with a catalog item behind it: that item on the map
 *   (`/map?item=<id>`);
 * - a town text: the town's card on the map (`/map?town=<osm ref>&ll=<lat>,<lng>&name=<name>`),
 *   located by the submission's own point, named by its title;
 * - a region text: the region's page, by the region's current slug, while the
 *   region is operational.
 *
 * Anything else has no target and its message carries no link. Only the
 * reader's own submissions: `userId` is the access rule, the message's ref id
 * is not.
 *
 * @see docs/specs/moderation-and-contribution.md §7.7
 *
 * @api
 */
final readonly class ApprovedSubmissionLinks
{
    public function __construct(private Connection $db)
    {
    }

    /**
     * @param list<int> $submissionIds
     *
     * @return array<int, array{kind: 'item', item: int}|array{kind: 'town', ref: string, ll: string, name: string}|array{kind: 'region', slug: string}>
     */
    public function for(array $submissionIds, int $userId): array
    {
        if ([] === $submissionIds) {
            return [];
        }
        $rows = $this->db->fetchAllAssociative(
            "SELECT id, type, item_id, title, payload,
                    CASE WHEN GeometryType(geom) = 'POINT' THEN ST_Y(geom) END AS lat,
                    CASE WHEN GeometryType(geom) = 'POINT' THEN ST_X(geom) END AS lng
               FROM submission
              WHERE id IN (:ids) AND user_id = :uid AND status = 'approved'",
            ['ids' => $submissionIds, 'uid' => $userId],
            ['ids' => ArrayParameterType::INTEGER],
        );

        $out = [];
        $regionOf = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            if (null !== $r['item_id']) {
                $out[$id] = ['kind' => 'item', 'item' => (int) $r['item_id']];
                continue;
            }
            if ('text' !== $r['type']) {
                continue;
            }
            $proposal = PlaceText::fromPayload(PlaceText::decode($r['payload']) ?? []);
            if (null === $proposal) {
                continue;
            }
            if (PlaceText::TOWN === $proposal['target']) {
                $town = self::town($proposal['ref'], $r['lat'], $r['lng'], (string) $r['title']);
                if (null !== $town) {
                    $out[$id] = $town;
                }
            } else {
                $regionOf[$id] = (int) $proposal['ref'];
            }
        }

        if ([] !== $regionOf) {
            $slugs = $this->db->fetchAllKeyValue(
                "SELECT r.id, r.slug FROM region r
                  WHERE r.id IN (:ids) AND r.geom IS NOT NULL AND r.country_code <> '' AND ".OperationalRegions::predicate('r'),
                ['ids' => array_values(array_unique($regionOf))],
                ['ids' => ArrayParameterType::INTEGER],
            );
            foreach ($regionOf as $id => $regionId) {
                $slug = $slugs[$regionId] ?? null;
                if (\is_string($slug) && 1 === preg_match('~^[a-z0-9-]+$~', $slug)) {
                    $out[$id] = ['kind' => 'region', 'slug' => $slug];
                }
            }
        }

        return $out;
    }

    /**
     * The town card's link parts, or null when the point is not a place on
     * Earth. `ll` is `<lat>,<lng>`, the order map.js reads it in.
     *
     * @return array{kind: 'town', ref: string, ll: string, name: string}|null
     */
    private static function town(string $ref, mixed $lat, mixed $lng, string $title): ?array
    {
        if (!is_numeric($lat) || !is_numeric($lng)) {
            return null;
        }
        $lat = (float) $lat;
        $lng = (float) $lng;
        if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
            return null;
        }

        return ['kind' => 'town', 'ref' => $ref, 'll' => sprintf('%.6F,%.6F', $lat, $lng), 'name' => trim($title)];
    }
}
