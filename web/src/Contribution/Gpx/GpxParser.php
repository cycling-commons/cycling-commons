<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Contribution\Gpx;

/**
 * Strict GPX intake parser (docs/specs/route-domain.md §4.1,
 * docs/specs/edit-items/R-quality-rides.md): reject, never coerce.
 *
 * Streamed with XMLReader, never loaded as a DOM. A 15 MiB body of tiny
 * elements builds hundreds of MB of libxml nodes as a DOM, outside PHP's
 * memory_limit, and the anonymous ride check reaches this parser. Streaming
 * keeps memory flat, and the element cap ends a body that is no GPX after
 * a bounded amount of work.
 *
 * @api
 */
final class GpxParser
{
    private const int MAX_BYTES = 15_728_640;   // 15 MiB (spec §4.1) - GPX XML is verbose; we still store only the simplified lat/lng track
    private const int MIN_POINTS = 2;
    private const int MAX_POINTS = 50_000;
    /**
     * A GPX point with time, elevation and sensor extensions runs to a couple
     * of dozen elements; 50 000 of them stay below this. Streaming keeps memory
     * flat whatever the count; the cap bounds the time a body that is no GPX
     * may take.
     */
    private const int MAX_ELEMENTS = 3_000_000;

    public function parse(string $xml): GpxTrack
    {
        if (\strlen($xml) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('propose_route.error.gpx_too_large');
        }

        $prev = libxml_use_internal_errors(true);
        try {
            $points = $this->readPoints($xml);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }

        $n = \count($points);
        if ($n < self::MIN_POINTS) {
            // Zero trkpt elements is invalid, not out-of-range.
            throw new \InvalidArgumentException(0 === $n ? 'propose_route.error.gpx_invalid' : 'propose_route.error.gpx_points');
        }

        return new GpxTrack($points);
    }

    /**
     * Every trkpt in document order, in any namespace, as [lat, lng, ele|null];
     * ele is the first one inside the point.
     *
     * @return list<array{0: float, 1: float, 2: float|null}>
     */
    private function readPoints(string $xml): array
    {
        libxml_clear_errors();
        $reader = \XMLReader::XML($xml, null, \LIBXML_NONET | \LIBXML_COMPACT);
        if (false === $reader) {
            throw new \InvalidArgumentException('propose_route.error.gpx_invalid');
        }

        $points = [];
        $elements = 0;
        $pointDepth = null;   // depth of the open trkpt, while inside one
        $ele = null;
        $eleSeen = false;
        try {
            while ($reader->read()) {
                if (\XMLReader::END_ELEMENT === $reader->nodeType && $reader->depth === $pointDepth) {
                    $points[\count($points) - 1][2] = $ele;
                    $pointDepth = null;
                    continue;
                }
                if (\XMLReader::ELEMENT !== $reader->nodeType) {
                    continue;
                }
                if (++$elements > self::MAX_ELEMENTS) {
                    throw new \InvalidArgumentException('propose_route.error.gpx_invalid');
                }

                if ('trkpt' === $reader->localName) {
                    if (\count($points) >= self::MAX_POINTS) {
                        // Reject as soon as we cross the cap.
                        throw new \InvalidArgumentException('propose_route.error.gpx_points');
                    }
                    $points[] = [...self::coordinates($reader), null];
                    $ele = null;
                    $eleSeen = false;
                    // A self-closing point has no children and no end tag to wait for.
                    $pointDepth = $reader->isEmptyElement ? null : $reader->depth;
                } elseif (null !== $pointDepth && 'ele' === $reader->localName && !$eleSeen) {
                    $eleSeen = true;
                    $text = $reader->readString();
                    $ele = is_numeric($text) ? (float) $text : null;
                }
            }
            // Errors only: libxml also reports warnings (a relative namespace
            // URI, say) for files that read perfectly well.
            foreach (libxml_get_errors() as $error) {
                if ($error->level >= \LIBXML_ERR_ERROR) {
                    throw new \InvalidArgumentException('propose_route.error.gpx_invalid');
                }
            }
        } finally {
            $reader->close();
        }

        return $points;
    }

    /** @return array{0: float, 1: float} */
    private static function coordinates(\XMLReader $trkpt): array
    {
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

        return [$lat, $lng];
    }
}
