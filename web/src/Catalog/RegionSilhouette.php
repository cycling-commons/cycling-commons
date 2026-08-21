<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

use Doctrine\DBAL\Connection;

/**
 * Inline SVG country outline with the region filled. Uses `region.outline`, not a slippy map.
 *
 * @api
 */
final class RegionSilhouette
{
    /** Drawing box in SVG user units. The viewBox scales it to any rendered size. */
    private const float W = 160.0;
    private const float H = 120.0;
    private const float PAD = 6.0;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return array{country: string, region: string, width: float, height: float}|null
     *                                                                                  null when neither shape is usable, so the template can omit the figure
     *                                                                                  entirely rather than render an empty box
     */
    public function forRegion(int $regionId, string $countryCode): ?array
    {
        $regionRings = $this->rings('SELECT outline FROM region WHERE id = :id', ['id' => $regionId]);
        // admin_level=2 for the same country — not LIMIT 1 on country_code (that would pick a province).
        $countryRings = $this->rings(
            'SELECT outline FROM region WHERE country_code = :cc AND admin_level = 2 LIMIT 1',
            ['cc' => $countryCode],
        );

        if ([] === $regionRings && [] === $countryRings) {
            return null;
        }

        // Frame on the country so every region of it is drawn at the same scale.
        $frame = [] !== $countryRings ? $countryRings : $regionRings;
        $box = self::bbox($frame);
        if (null === $box) {
            return null;
        }

        return [
            'country' => self::path($countryRings, $box),
            'region' => self::path($regionRings, $box),
            'width' => self::W,
            'height' => self::H,
        ];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return list<list<float>> flat [x,y,x,y,…] rings, as `region.outline` stores them
     */
    private function rings(string $sql, array $params): array
    {
        $raw = $this->db->fetchOne($sql, $params);
        if (!\is_string($raw)) {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!\is_array($decoded)) {
            return [];
        }

        $rings = [];
        foreach ($decoded as $ring) {
            // A ring needs ≥3 points (≥6 values); shorter is a rounding artifact.
            if (\is_array($ring) && \count($ring) >= 6) {
                $rings[] = array_map(floatval(...), array_values($ring));
            }
        }

        return $rings;
    }

    /**
     * @param list<list<float>> $rings
     *
     * @return array{minX: float, maxX: float, minY: float, maxY: float, k: float}|null
     */
    private static function bbox(array $rings): ?array
    {
        $minX = $minY = \INF;
        $maxX = $maxY = -\INF;
        foreach ($rings as $ring) {
            for ($i = 0, $n = \count($ring) - 1; $i < $n; $i += 2) {
                $minX = min($minX, $ring[$i]);
                $maxX = max($maxX, $ring[$i]);
                $minY = min($minY, $ring[$i + 1]);
                $maxY = max($maxY, $ring[$i + 1]);
            }
        }
        if (\INF === $minX || $maxX <= $minX || $maxY <= $minY) {
            return null;
        }

        // Longitude degrees shrink toward the poles; cos(mid-lat) keeps the silhouette recognizable.
        return [
            'minX' => $minX, 'maxX' => $maxX, 'minY' => $minY, 'maxY' => $maxY,
            'k' => max(0.15, cos(deg2rad(($minY + $maxY) / 2))),
        ];
    }

    /**
     * @param list<list<float>>                                                   $rings
     * @param array{minX: float, maxX: float, minY: float, maxY: float, k: float} $box
     */
    private static function path(array $rings, array $box): string
    {
        if ([] === $rings) {
            return '';
        }
        $spanX = ($box['maxX'] - $box['minX']) * $box['k'];
        $spanY = $box['maxY'] - $box['minY'];
        $scale = min((self::W - 2 * self::PAD) / $spanX, (self::H - 2 * self::PAD) / $spanY);
        // Centre in the box so a wide country and a tall one both sit in the middle.
        $offX = (self::W - $spanX * $scale) / 2;
        $offY = (self::H - $spanY * $scale) / 2;

        $out = [];
        foreach ($rings as $ring) {
            $d = '';
            for ($i = 0, $n = \count($ring) - 1; $i < $n; $i += 2) {
                $x = $offX + ($ring[$i] - $box['minX']) * $box['k'] * $scale;
                // SVG y grows downward; latitude grows upward.
                $y = $offY + ($box['maxY'] - $ring[$i + 1]) * $scale;
                $d .= ('' === $d ? 'M' : 'L').round($x, 1).' '.round($y, 1);
            }
            if ('' !== $d) {
                $out[] = $d.'Z';
            }
        }

        return implode(' ', $out);
    }
}
