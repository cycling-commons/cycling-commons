<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Api\V1;

use App\Api\V1\Dto\PhotoEntry;
use App\Api\V1\Dto\RouteLine;
use App\Catalog\BikeTypeVocabulary;
use App\Catalog\DifficultyVocabulary;
use App\Catalog\ItemState;
use App\Media\PhotoPlace;
use App\Media\PhotoValidator;
use App\Media\PublicPhotos;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * `/v1/routes` and `/v1/routes/{id}` (docs/specs/public-api.md §2.2): the
 * served recommended routes with their whole line. Must not join
 * contributor identity (docs/specs/public-api-personal-data-boundary.md):
 * `proposed_by`, `trashed_by` and the rider's own words stay home.
 *
 * @api
 */
final readonly class PublicRoutesProvider
{
    /** Six decimals is about ten centimetres, the bulk export's precision. */
    private const int PRECISION = 6;

    public function __construct(
        private Connection $db,
        #[Autowire('%env(DEFAULT_URI)%')]
        private string $siteUrl,
    ) {
    }

    /**
     * Served routes crossing a box, by id, after `$afterId`.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $bbox
     * @param 'community'|'curated'|null                    $tier
     *
     * @return list<RouteLine>
     */
    public function inBox(array $bbox, int $limit, int $afterId, ?string $tier): array
    {
        $sql = $this->select().' AND rr.geom && ST_MakeEnvelope(:minLon, :minLat, :maxLon, :maxLat, 4326) AND rr.id > :after';
        if ('curated' === $tier) {
            $sql .= " AND rr.state = 'verified'";
        } elseif ('community' === $tier) {
            $sql .= " AND rr.state <> 'verified'";
        }
        $rows = $this->db->fetchAllAssociative(
            $sql.' ORDER BY rr.id LIMIT :lim',
            ['minLon' => $bbox[0], 'minLat' => $bbox[1], 'maxLon' => $bbox[2], 'maxLat' => $bbox[3], 'after' => $afterId, 'lim' => $limit],
            ['lim' => ParameterType::INTEGER],
        );

        return array_values(array_filter(array_map(fn (array $row): ?RouteLine => $this->line($row, false), $rows)));
    }

    /** One served route in full, or null. */
    public function route(int $id): ?RouteLine
    {
        $row = $this->db->fetchAssociative($this->select().' AND rr.id = :id', ['id' => $id]);

        return false === $row ? null : $this->line($row, true);
    }

    private function select(): string
    {
        return 'SELECT rr.id, rr.name, ST_AsGeoJSON(rr.geom, '.self::PRECISION.') AS geom, (rr.state = \'verified\') AS verified,
                       COALESCE(rr.distance_m, 0) AS distance_m, COALESCE(rr.ascent_m, 0) AS ascent_m, rr.attributes,
                       ST_Y(ST_PointOnSurface(rr.geom)) AS pin_lat, ST_X(ST_PointOnSurface(rr.geom)) AS pin_lng,
                       r.slug AS region
                FROM recommended_route rr
                LEFT JOIN region r ON r.id = rr.region_id
                WHERE rr.geom IS NOT NULL AND rr.state IN '.ItemState::servedSqlTuple();
    }

    /** @param array<string, mixed> $row */
    private function line(array $row, bool $detail): ?RouteLine
    {
        $geometry = \is_string($row['geom']) ? json_decode($row['geom'], true) : null;
        if (!\is_array($geometry)) {
            return null;
        }
        /** @var array<string, mixed> $attrs */
        $attrs = \is_string($row['attributes']) ? (json_decode($row['attributes'], true) ?: []) : [];

        return new RouteLine(
            (int) $row['id'],
            (string) $row['name'],
            $row['verified'] ? 'curated' : 'community',
            $geometry,
            (int) $row['distance_m'],
            (int) $row['ascent_m'],
            \is_string($row['region']) ? $row['region'] : null,
            // route-domain.md §9: one canonical shape on every serving path.
            DifficultyVocabulary::canonical($attrs['difficulty'] ?? null),
            self::text($attrs['dominantSurface'] ?? null),
            self::strings($attrs['season'] ?? null),
            BikeTypeVocabulary::normalize($attrs['bikeTypes'] ?? null),
            $detail ? $this->detail($row, $attrs) : null,
        );
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $attrs
     *
     * @return array<string, mixed>
     */
    private function detail(array $row, array $attrs): array
    {
        // The photos the map shows on this route, judged at a point on its line.
        $shown = PublicPhotos::of(PhotoValidator::sift($attrs, PhotoPlace::route($row['pin_lat'], $row['pin_lng']))['attributes']);

        return [
            // A curator's 1-5 marks, not a vote tally.
            'ratings' => [
                'quietness' => self::mark($attrs['quietness'] ?? null),
                'scenic' => self::mark($attrs['scenic'] ?? null),
                'friendliness' => self::mark($attrs['friendliness'] ?? null),
            ],
            'surfaces' => self::surfaces($attrs['surfaces'] ?? null),
            'gradient_limited' => self::text($attrs['gradientLimited'] ?? null),
            'best_direction' => self::text($attrs['bestDirection'] ?? null),
            'elevation' => self::numbers($attrs['elev'] ?? null),
            'photos' => PhotoEntry::allOf($shown, $this->siteUrl),
            'gpx' => '/v1/routes/'.(int) $row['id'].'.gpx',
        ];
    }

    private static function text(mixed $v): ?string
    {
        return \is_string($v) && '' !== trim($v) ? $v : null;
    }

    /**
     * A list of words; a single stored word (older rows keep `season` as one) is a list of one.
     *
     * @return list<string>
     */
    private static function strings(mixed $v): array
    {
        if (\is_string($v)) {
            $v = [$v];
        }

        return \is_array($v) ? array_values(array_filter($v, static fn (mixed $s): bool => \is_string($s) && '' !== $s)) : [];
    }

    private static function mark(mixed $v): ?int
    {
        return (\is_string($v) || \is_int($v)) && \in_array((int) $v, [1, 2, 3, 4, 5], true) && (string) (int) $v === (string) $v ? (int) $v : null;
    }

    /** @return list<float>|null */
    private static function numbers(mixed $v): ?array
    {
        if (!\is_array($v) || [] === $v || !array_is_list($v)) {
            return null;
        }
        foreach ($v as $n) {
            if (!\is_int($n) && !\is_float($n)) {
                return null;
            }
        }

        return array_map(floatval(...), $v);
    }

    /**
     * The measured surface mix (route-domain.md §9): `parts` of `{surface, pct}`
     * and the share of the line it covers.
     *
     * @return array{parts: list<array{surface: string, pct: int}>, covered: float|int|null}|null
     */
    private static function surfaces(mixed $v): ?array
    {
        if (!\is_array($v) || !\is_array($v['parts'] ?? null)) {
            return null;
        }
        $parts = [];
        foreach ($v['parts'] as $part) {
            if (\is_array($part) && \is_string($part['surface'] ?? null) && is_numeric($part['pct'] ?? null)) {
                $parts[] = ['surface' => $part['surface'], 'pct' => (int) $part['pct']];
            }
        }
        $covered = $v['covered'] ?? null;

        return ['parts' => $parts, 'covered' => \is_int($covered) || \is_float($covered) ? $covered : null];
    }
}
