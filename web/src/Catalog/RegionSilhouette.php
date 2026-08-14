<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

use Doctrine\DBAL\Connection;

/**
 * The little drawing at the top of a region page: the country in outline with
 * the region filled in (owner request, 2026-08-14, "add a drawing of the
 * country and the region selected highlighted").
 *
 * WHY NOT A MAP. A region page is read once, often on a phone, to answer
 * "where is this and what is here". A slippy map for that costs a MapLibre
 * boot, a style fetch and a tile round-trip, and gives back panning nobody
 * asked for. This is a few hundred bytes of inline SVG in the HTML that is
 * already being sent: no JavaScript, no requests, no layout shift, and it
 * prints. The "Open this region on the map" button is right beside it for
 * anyone who wants the real thing.
 *
 * WHY IT IS FREE. `region.outline` already exists and already holds exactly
 * this: exterior rings only, simplified to 0.05° and rounded to 3 decimals,
 * with parts under max(1% of the region, 5 km²) dropped. It was built for the
 * scope chips' ranking, and it is a better source for a thumbnail than the
 * real geometry would be, which is tens of thousands of vertices per country.
 *
 * The country shape is the L2 outline row, which every onboarded country has
 * (tools/divisions/README.md). A country whose operating level IS L2 — Slovenia,
 * Luxembourg — is its own country shape, so the region path is drawn alone and
 * the drawing degrades to a plain silhouette rather than to nothing.
 *
 * @api Used by PageController::regionDetail().
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
        // The country outline is the admin_level=2 row for the same country.
        // Not `LIMIT 1` on country_code: that would pick whichever region sorted
        // first and draw a province as if it were the country.
        $countryRings = $this->rings(
            'SELECT outline FROM region WHERE country_code = :cc AND admin_level = 2 LIMIT 1',
            ['cc' => $countryCode],
        );

        if ([] === $regionRings && [] === $countryRings) {
            return null;
        }

        // Framed on the COUNTRY when there is one, so every region of a country
        // is drawn at the same scale and in the same place: flicking between two
        // of them shows the highlight moving, not the whole picture rescaling.
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
            // A ring needs at least three points to enclose anything; anything
            // shorter is a rounding artifact, not a shape.
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

        // Longitude degrees shrink toward the poles, so a plate-carrée drawing
        // makes Norway fat and Chile thin. cos(mid-latitude) is the one-line
        // correction that keeps a country the shape people recognise, which is
        // the entire job of this picture.
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
        // Centred in the box, so a wide country and a tall one both sit in the
        // middle instead of hugging a corner.
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
