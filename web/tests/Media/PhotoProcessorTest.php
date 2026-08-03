<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Media;

use App\Media\PhotoProcessor;
use App\Media\PhotoRejected;
use App\Media\XmpRights;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The processor contract (docs/specs/photo-uploads.md §1.3, §1.3b, §1.3c, §1.4,
 * §3, §7): harvest the two facts worth keeping, bake orientation into pixels,
 * strip every inherited profile, cap the original at 4K, never upscale, emit
 * WebP and only WebP, then write back the one rights block the app authors
 * itself.
 *
 * Inputs are generated in-test rather than committed as binary fixtures,
 * including a hand-built EXIF APP1 profile — so what is asserted about EXIF is
 * asserted against a structure this file fully describes.
 */
final class PhotoProcessorTest extends TestCase
{
    #[\Override]
    public static function setUpBeforeClass(): void
    {
        if ([] === \Imagick::queryFormats('WEBP')) {
            self::markTestSkipped('This host\'s ImageMagick has no WebP delegate; the app container (Dockerfile) does.');
        }
    }

    /** A little-endian rational, as EXIF stores them. */
    private static function rational(int $numerator, int $denominator): string
    {
        return pack('VV', $numerator, $denominator);
    }

    /**
     * A minimal but genuine EXIF APP1 payload: IFD0 pointing at an Exif IFD
     * (DateTimeOriginal) and a GPS IFD (50°29'30"N 5°51'12"E). Offsets are
     * relative to the start of the TIFF header, so they are fixed constants:
     * IFD0 at 8 (2 entries = 30 bytes), Exif IFD at 38 (1 entry = 18 bytes),
     * GPS IFD at 56 (4 entries = 54 bytes), then the out-of-line data.
     */
    private static function exifProfile(): string
    {
        $dateTimeOriginal = "2025:10:04 09:12:33\0";                                        // 20 bytes
        $latitude = self::rational(50, 1).self::rational(29, 1).self::rational(3000, 100);  // 24 bytes
        $longitude = self::rational(5, 1).self::rational(51, 1).self::rational(1200, 100);  // 24 bytes

        $ifd0At = 8;
        $exifIfdAt = 38;
        $gpsIfdAt = 56;
        $dateAt = 110;
        $latAt = 130;
        $lngAt = 154;

        $tiff = 'II'.pack('v', 42).pack('V', $ifd0At);
        $tiff .= pack('v', 2)
            .pack('vvVV', 0x8769, 4, 1, $exifIfdAt)   // ExifIFDPointer
            .pack('vvVV', 0x8825, 4, 1, $gpsIfdAt)    // GPSInfoIFDPointer
            .pack('V', 0);
        $tiff .= pack('v', 1)
            .pack('vvVV', 0x9003, 2, 20, $dateAt)     // DateTimeOriginal
            .pack('V', 0);
        $tiff .= pack('v', 4)
            .pack('vvV', 0x0001, 2, 2)."N\0\0\0"      // GPSLatitudeRef (inline)
            .pack('vvVV', 0x0002, 5, 3, $latAt)       // GPSLatitude
            .pack('vvV', 0x0003, 2, 2)."E\0\0\0"      // GPSLongitudeRef (inline)
            .pack('vvVV', 0x0004, 5, 3, $lngAt)       // GPSLongitude
            .pack('V', 0);
        $tiff .= $dateTimeOriginal.$latitude.$longitude;

        return "Exif\0\0".$tiff;
    }

    private static function image(int $width, int $height, string $format = 'jpeg', bool $withExif = false): string
    {
        $image = new \Imagick();
        $image->newImage($width, $height, 'red');
        $image->setImageFormat($format);
        if ($withExif) {
            $image->profileImage('exif', self::exifProfile());
        }
        $bytes = $image->getImageBlob();
        $image->clear();

        return $bytes;
    }

    /** @return array{int, int} */
    private static function dimensions(string $webp): array
    {
        $image = new \Imagick();
        $image->readImageBlob($webp);
        $size = [$image->getImageWidth(), $image->getImageHeight()];
        $image->clear();

        return $size;
    }

    /** @return list<string> */
    private static function profiles(string $webp): array
    {
        $image = new \Imagick();
        $image->readImageBlob($webp);
        $names = array_keys($image->getImageProfiles('*'));
        $image->clear();

        return array_values($names);
    }

    public function testEmitsWebpTrioAndStripsEveryInheritedProfile(): void
    {
        $processed = (new PhotoProcessor())->process(self::image(2000, 1500, withExif: true));

        foreach (['orig' => $processed->orig, 'lg' => $processed->lg, 'sm' => $processed->sm] as $name => $bytes) {
            $image = new \Imagick();
            $image->readImageBlob($bytes);
            self::assertSame('WEBP', $image->getImageFormat(), $name.' must be WebP');
            $image->clear();
            self::assertSame([], self::profiles($bytes), $name.' must carry nothing inherited from the camera');
        }

        self::assertSame(2000, $processed->width);
        self::assertSame(1500, $processed->height);
        self::assertSame([1400, 1050], self::dimensions($processed->lg));
        self::assertSame([520, 390], self::dimensions($processed->sm));
    }

    public function testTheAuthoredRightsPacketReachesOrigAndLgButNeverSm(): void
    {
        $packet = (new XmpRights('https://cyclingcommons.example'))
            ->forPhoto(Uuid::fromString('0198c0de-0000-7000-8000-000000000001'));
        $processed = (new PhotoProcessor())->process(self::image(2000, 1500, withExif: true), $packet);

        self::assertSame(['xmp'], self::profiles($processed->orig), 'the reuse asset states its licence');
        self::assertSame(['xmp'], self::profiles($processed->lg), 'the variant people right-click-save states it too');
        self::assertSame([], self::profiles($processed->sm), 'a thumbnail nobody redistributes carries no 1.1 KB packet');

        $embedded = new \Imagick();
        $embedded->readImageBlob($processed->orig);
        $xmp = (string) $embedded->getImageProfile('xmp');
        $embedded->clear();

        self::assertStringContainsString('CC BY-SA 4.0', $xmp);
        self::assertStringContainsString('0198c0de-0000-7000-8000-000000000001', $xmp, 'the photo page is the attribution target');
    }

    public function testWithoutAPacketNothingIsWrittenBack(): void
    {
        $processed = (new PhotoProcessor())->process(self::image(1200, 900, withExif: true));

        self::assertSame([], self::profiles($processed->orig), 'the processor never invents metadata of its own');
    }

    public function testHarvestsCaptureDateAndCoordinatesBeforeStripping(): void
    {
        $processed = (new PhotoProcessor())->process(self::image(2000, 1500, withExif: true));

        self::assertSame('2025-10-04 09:12:33', $processed->takenAt?->format('Y-m-d H:i:s'));
        self::assertNotNull($processed->gpsLat);
        self::assertNotNull($processed->gpsLng);
        self::assertEqualsWithDelta(50.491667, $processed->gpsLat, 0.000001);
        self::assertEqualsWithDelta(5.853333, $processed->gpsLng, 0.000001);
    }

    public function testAPhotoWithoutExifHarvestsNothingAndStillProcesses(): void
    {
        $processed = (new PhotoProcessor())->process(self::image(1200, 900, 'png'));

        self::assertNull($processed->takenAt);
        self::assertNull($processed->gpsLat);
        self::assertNull($processed->gpsLng);
        self::assertSame([520, 390], self::dimensions($processed->sm));
    }

    public function testCapsTheStoredOriginalAtFourK(): void
    {
        $landscape = (new PhotoProcessor())->process(self::image(6000, 4000));
        self::assertSame(3840, $landscape->width, 'longest side capped at 3840');
        self::assertSame(2560, $landscape->height);

        $portrait = (new PhotoProcessor())->process(self::image(3000, 5000, 'png'));
        self::assertSame(3840, $portrait->height, 'the cap follows the longest side, whichever it is');
        self::assertSame(2304, $portrait->width);
    }

    public function testNeverUpscales(): void
    {
        $processed = (new PhotoProcessor())->process(self::image(900, 600));

        self::assertSame(900, $processed->width);
        self::assertSame([900, 600], self::dimensions($processed->lg), 'lg never exceeds the input');
        self::assertSame([520, 347], self::dimensions($processed->sm));
    }

    public function testRejectsAnImageBelowTheMinimumShortSide(): void
    {
        $this->expectException(PhotoRejected::class);

        try {
            (new PhotoProcessor())->process(self::image(300, 150));
        } catch (PhotoRejected $rejected) {
            self::assertSame('photo_too_small', $rejected->reason);

            throw $rejected;
        }
    }

    public function testRejectsUndecodableBytes(): void
    {
        $this->expectException(PhotoRejected::class);

        try {
            (new PhotoProcessor())->process('this is not an image at all');
        } catch (PhotoRejected $rejected) {
            self::assertSame('photo_unreadable', $rejected->reason);

            throw $rejected;
        }
    }

    /**
     * The property under test is that a GIF does not get in. WHICH layer stops
     * it depends on where the suite runs, and both answers are correct:
     *
     * - with the shipped ImageMagick policy (web/docker/imagemagick-policy.xml,
     *   so: the app image, and production) the GIF coder is denied outright and
     *   the file never decodes — `photo_unreadable`;
     * - without it (a developer's host ImageMagick) the bytes decode and
     *   PhotoProcessor's own allowlist rejects the format — `photo_format`.
     *
     * The bytes are a literal rather than something Imagick writes for us,
     * because under that same policy this process cannot produce a GIF either.
     */
    public function testRejectsAnUnsupportedFormatByItsContentNotItsName(): void
    {
        $gif = base64_decode('R0lGODdhAQABAIAAAP///////ywAAAAAAQABAAACAkQBADs=', true);
        self::assertIsString($gif);

        $this->expectException(PhotoRejected::class);

        try {
            (new PhotoProcessor())->process($gif);
        } catch (PhotoRejected $rejected) {
            self::assertContains(
                $rejected->reason,
                ['photo_format', 'photo_unreadable'],
                'a GIF must be refused, by the coder policy or by our own allowlist',
            );

            throw $rejected;
        }
    }

    /**
     * The decompression bomb: a complete, entirely valid PNG, well under the
     * 15 MB cap, that decodes to 64 megapixels. Nothing about the file is
     * malformed — the byte cap has no reason to stop it, and by the time the
     * old dimension check ran the pixels had already been allocated.
     *
     * The assertion that matters is the REASON. A rejection alone would prove
     * little (a decoder that fell over would also raise one); getting
     * `photo_too_many_pixels` is what says we refused it on the header,
     * before allocating anything.
     */
    public function testRejectsADecompressionBombOnItsHeaderAlone(): void
    {
        $bomb = self::pngBomb(8_000, 8_000);
        self::assertLessThan(
            PhotoProcessor::MAX_BYTES,
            \strlen($bomb),
            'a bomb the byte cap already stops would not be testing anything',
        );

        $this->expectException(PhotoRejected::class);

        try {
            (new PhotoProcessor())->process($bomb);
        } catch (PhotoRejected $rejected) {
            self::assertSame('photo_too_many_pixels', $rejected->reason);

            throw $rejected;
        }
    }

    /**
     * A complete greyscale PNG of $width x $height, every pixel zero — which
     * is why it deflates to a rounding error of its decoded size.
     *
     * Hand-built rather than produced with Imagick: newImage() would allocate
     * the canvas inside the test, which is the very cost the code under test
     * exists to avoid paying.
     */
    private static function pngBomb(int $width, int $height): string
    {
        $chunk = static function (string $type, string $data): string {
            return pack('N', \strlen($data)).$type.$data.pack('N', crc32($type.$data));
        };

        // 8-bit greyscale, no interlace.
        $ihdr = pack('NN', $width, $height)."\x08\x00\x00\x00\x00";

        // Each scanline is a filter byte followed by its pixels, all zeroes.
        // Deflated a row at a time rather than assembled and compressed in one
        // go: the uncompressed image is 64 MB, which is over the container's
        // memory_limit, and a test that has to allocate the bomb to prove we
        // do not allocate the bomb is not much of a test.
        $stream = deflate_init(\ZLIB_ENCODING_DEFLATE, ['level' => 9]);
        self::assertNotFalse($stream);
        $row = str_repeat("\x00", $width + 1);
        $idat = '';
        for ($y = 0; $y < $height; ++$y) {
            $idat .= deflate_add($stream, $row, \ZLIB_NO_FLUSH);
        }
        $idat .= deflate_add($stream, '', \ZLIB_FINISH);

        return "\x89PNG\r\n\x1a\n".$chunk('IHDR', $ihdr).$chunk('IDAT', $idat).$chunk('IEND', '');
    }
}
