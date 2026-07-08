<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Contribution\Gpx;

/**
 * Strict GPX intake parser (route-domain spec §5.3): reject, never coerce.
 *
 * DOMDocument + getElementsByTagNameNS('*', …) so GPX 1.0/1.1 files parse
 * regardless of their default namespace. XXE-safe: PHP ≥8.0 never loads
 * external entities unless explicitly re-enabled; LIBXML_NONET additionally
 * forbids network fetches during parse.
 *
 * Every failure throws InvalidArgumentException whose message is a
 * translation key (propose_route.error.*) — the controller surfaces it as a
 * form error, mirroring the ClimbGeometry/ContributeController pattern.
 *
 * @api Public entry point for GPX intake (route-domain spec §5.3); covered by GpxParserTest.
 */
final class GpxParser
{
    private const int MAX_BYTES = 2_097_152;   // 2 MB (spec §5.3)
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
                // Reject the moment we cross the cap — no point buffering the
                // rest of a track we are going to refuse.
                throw new \InvalidArgumentException('propose_route.error.gpx_points');
            }
        }

        $n = \count($points);
        if ($n < self::MIN_POINTS) {
            // Zero trkpt elements means "not a GPX track" rather than a size
            // problem — report it as invalid, not out-of-range.
            throw new \InvalidArgumentException(0 === $n ? 'propose_route.error.gpx_invalid' : 'propose_route.error.gpx_points');
        }

        return new GpxTrack($points);
    }
}
