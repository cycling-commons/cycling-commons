<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Api\V1;

use App\Coverage\CoverageRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * `?hydrate=osm` (docs/specs/public-api.md §4): the OpenStreetMap subset we
 * cache for an object, read from the coverage table the harvest fills, with
 * the tags the map's drawer may show (CoverageRepository::TAG_WHITELIST) but
 * its free text (FREE_TEXT_TAGS), and nothing else. OpenStreetMap's data, under the ODbL: the response carries
 * `© OpenStreetMap contributors` with it.
 *
 * @api
 */
final readonly class OsmSubsets
{
    /**
     * Tags the map's drawer shows that hold prose a mapper wrote: the API
     * carries no free text (public-api-personal-data-boundary.md), OSM's
     * included. A consumer that wants it reads OpenStreetMap by the ref.
     */
    private const array FREE_TEXT_TAGS = ['description'];

    public function __construct(private Connection $db)
    {
    }

    /**
     * @param list<string> $refs `node/…` or `way/…`
     *
     * @return array<string, array{ref: string, name: string|null, tags: object}> by ref; a ref we hold nothing for is absent
     */
    public function byRef(array $refs): array
    {
        $refs = array_values(array_unique(array_filter($refs, static fn (string $r): bool => 1 === preg_match('~^(node|way|relation)/\d{1,16}$~', $r))));
        // The harvest creates the table; a fresh database has none yet.
        if ([] === $refs || null === $this->db->fetchOne("SELECT to_regclass('public.coverage_poi')")) {
            return [];
        }

        /** @var list<array{ref: string, name: string|null, tags: string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT DISTINCT ON (cp.ref) cp.ref, cp.name, cp.tags FROM coverage_poi cp WHERE cp.ref IN (:refs) ORDER BY cp.ref, cp.letter',
            ['refs' => $refs],
            ['refs' => ArrayParameterType::STRING],
        );
        $whitelist = array_flip(array_diff(CoverageRepository::TAG_WHITELIST, self::FREE_TEXT_TAGS));
        $out = [];
        foreach ($rows as $row) {
            $tags = json_decode($row['tags'], true);
            $out[$row['ref']] = [
                'ref' => $row['ref'],
                'name' => $row['name'],
                // (object) so an empty intersection still encodes {}.
                'tags' => (object) (\is_array($tags) ? array_intersect_key($tags, $whitelist) : []),
            ];
        }

        return $out;
    }
}
