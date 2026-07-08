<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Contribution\Gpx;

use App\Contribution\Gpx\GpxParser;
use PHPUnit\Framework\TestCase;

/**
 * Spec §5.3: strict server-side GPX validation — reject, never coerce.
 * Points come out as [lat, lng, ele|null] triples.
 */
final class GpxParserTest extends TestCase
{
    private const string GPX_11 = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <gpx version="1.1" creator="test" xmlns="http://www.topografix.com/GPX/1/1">
          <trk><name>Condroz test</name><trkseg>
            <trkpt lat="50.400" lon="5.200"><ele>120.5</ele></trkpt>
            <trkpt lat="50.410" lon="5.210"><ele>141.0</ele></trkpt>
            <trkpt lat="50.420" lon="5.220"><ele>133.2</ele></trkpt>
          </trkseg></trk>
        </gpx>
        XML;

    public function testParsesNamespacedGpx11TrackWithElevation(): void
    {
        $track = new GpxParser()->parse(self::GPX_11);

        self::assertCount(3, $track->points);
        self::assertSame([50.400, 5.200, 120.5], $track->points[0]);
        self::assertSame([50.420, 5.220, 133.2], $track->points[2]);
    }

    public function testMissingElevationYieldsNullThirdElement(): void
    {
        $xml = str_replace(['<ele>120.5</ele>', '<ele>141.0</ele>', '<ele>133.2</ele>'], '', self::GPX_11);
        $track = new GpxParser()->parse($xml);

        self::assertNull($track->points[0][2]);
    }

    public function testConcatenatesMultipleSegments(): void
    {
        $xml = str_replace(
            '</trkseg></trk>',
            '</trkseg><trkseg><trkpt lat="50.430" lon="5.230"><ele>150</ele></trkpt></trkseg></trk>',
            self::GPX_11,
        );
        self::assertCount(4, new GpxParser()->parse($xml)->points);
    }

    public function testRejectsMalformedXml(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('propose_route.error.gpx_invalid');
        new GpxParser()->parse('<gpx><trk><trkseg>');
    }

    public function testRejectsGpxWithoutTrackPoints(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('propose_route.error.gpx_invalid');
        new GpxParser()->parse('<?xml version="1.0"?><gpx version="1.1"><wpt lat="50" lon="5"/></gpx>');
    }

    public function testRejectsSinglePointTrack(): void
    {
        $xml = preg_replace('/<trkpt lat="50\.41.*?<\/trkpt>\s*<trkpt lat="50\.42.*?<\/trkpt>/s', '', self::GPX_11);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('propose_route.error.gpx_points');
        new GpxParser()->parse($xml);
    }

    public function testRejectsMoreThanFiftyThousandPoints(): void
    {
        $pts = '';
        for ($i = 0; $i <= 50_000; ++$i) {
            $pts .= sprintf('<trkpt lat="50.%05d" lon="5.1"/>', $i % 90_000);
        }
        $xml = '<?xml version="1.0"?><gpx version="1.1"><trk><trkseg>'.$pts.'</trkseg></trk></gpx>';
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('propose_route.error.gpx_points');
        new GpxParser()->parse($xml);
    }

    public function testRejectsOutOfRangeCoordinates(): void
    {
        $xml = str_replace('lat="50.410"', 'lat="99999"', self::GPX_11);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('propose_route.error.gpx_coords');
        new GpxParser()->parse($xml);
    }

    public function testRejectsNonNumericCoordinates(): void
    {
        $xml = str_replace('lat="50.410"', 'lat="abc"', self::GPX_11);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('propose_route.error.gpx_coords');
        new GpxParser()->parse($xml);
    }

    public function testRejectsOversizedInput(): void
    {
        $xml = str_replace('creator="test"', 'creator="'.str_repeat('x', 2_097_153).'"', self::GPX_11);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('propose_route.error.gpx_too_large');
        new GpxParser()->parse($xml);
    }
}
