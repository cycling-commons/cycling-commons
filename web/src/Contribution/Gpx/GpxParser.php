<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Contribution\Gpx;

/**
 * Strict GPX intake parser (docs/specs/route-domain.md §4.1,
 * docs/specs/edit-items/R-quality-rides.md): reject, never coerce.
 *
 * @api
 */
final class GpxParser
{
    private const int MAX_BYTES = 15_728_640;   // 15 MiB (spec §4.1) - GPX XML is verbose; we still store only the simplified lat/lng track
    private const int MIN_POINTS = 2;
    private const int MAX_POINTS = 50_000;

    public function parse(string $xml): GpxTrack
    {
        if (\strlen($xml) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('propose_route.error.gpx_too_large');
        }

        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        try {
            if (!$doc->loadXML($xml, \LIBXML_NONET | \LIBXML_COMPACT)) {
                throw new \InvalidArgumentException('propose_route.error.gpx_invalid');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }

        $points = [];
        foreach ($doc->getElementsByTagNameNS('*', 'trkpt') as $trkpt) {
            $lat = $trkpt->getAttribute('lat');
            $lng = $trkpt->getAttribute('lon');
            if (!is_numeric($lat) || !is_numeric($lng)) {
                throw new \InvalidArgumentException('propose_route.error.gpx_coords');
            }
            $lat = (float) $lat;
            $lng = (float) $lng;
            if (!is_finite($lat) || !is_finite($lng) || $lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
                throw new \InvalidArgumentException('propose_route.error.gpx_coords');
            }

            $ele = null;
            foreach ($trkpt->getElementsByTagNameNS('*', 'ele') as $eleNode) {
                $ele = is_numeric($eleNode->textContent) ? (float) $eleNode->textContent : null;
                break;
            }

            $points[] = [$lat, $lng, $ele];
            if (\count($points) > self::MAX_POINTS) {
                // Reject as soon as we cross the cap.
                throw new \InvalidArgumentException('propose_route.error.gpx_points');
            }
        }

        $n = \count($points);
        if ($n < self::MIN_POINTS) {
            // Zero trkpt elements is invalid, not out-of-range.
            throw new \InvalidArgumentException(0 === $n ? 'propose_route.error.gpx_invalid' : 'propose_route.error.gpx_points');
        }

        return new GpxTrack($points);
    }
}
