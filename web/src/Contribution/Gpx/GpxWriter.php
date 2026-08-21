<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Contribution\Gpx;

/**
 * Emits GPX 1.1 for a served route (docs/specs/route-domain.md §6).
 *
 * @api
 */
final class GpxWriter
{
    private const string ODBL_URL = 'https://opendatacommons.org/licenses/odbl/1-0/';

    /** @param list<array{0: float, 1: float}> $lngLatPairs */
    public function write(string $name, array $lngLatPairs): string
    {
        $w = new \XMLWriter();
        $w->openMemory();
        $w->setIndent(true);
        $w->startDocument('1.0', 'UTF-8');

        $w->startElement('gpx');
        $w->writeAttribute('version', '1.1');
        $w->writeAttribute('creator', 'Cycling Commons');
        $w->writeAttribute('xmlns', 'http://www.topografix.com/GPX/1/1');

        $w->startElement('metadata');
        $w->writeElement('name', $name);
        $w->startElement('copyright');
        $w->writeAttribute('author', 'Cycling Commons contributors');
        $w->writeElement('license', self::ODBL_URL);
        $w->endElement();
        $w->endElement();

        $w->startElement('trk');
        $w->writeElement('name', $name);
        $w->startElement('trkseg');
        foreach ($lngLatPairs as [$lng, $lat]) {
            $w->startElement('trkpt');
            $w->writeAttribute('lat', (string) $lat);
            $w->writeAttribute('lon', (string) $lng);
            $w->endElement();
        }
        $w->endElement();
        $w->endElement();

        $w->endElement();
        $w->endDocument();

        return $w->outputMemory();
    }
}
