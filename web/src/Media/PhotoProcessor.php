<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media;

/**
 * Decode → harvest → orient → strip → encode → state the licence, in that
 * order and only that order (docs/specs/photo-uploads.md §3 processing steps).
 *
 * The order is the whole point. The two facts worth keeping are read while the
 * metadata still exists; the orientation flag is baked into the pixels while it
 * still exists; then EVERY inherited profile is destroyed — EXIF including GPS,
 * IPTC, XMP, ICC — so the bytes that reach storage carry nothing the camera
 * wrote: no author field, no serial number, no coordinates. Camera make and
 * model are deliberately not harvested: no real use, and a device model is a
 * fingerprinting crumb.
 *
 * Only after that is one packet written back, and only the one the caller hands
 * in ({@see XmpRights}) — the licence and a link, never a name (§1.3c). It goes
 * into `orig` and `lg`, the two variants a reuser plausibly saves, and never
 * into `sm`, where ~1.1 KB would more than triple a sub-kilobyte thumbnail.
 *
 * Format is decided by decoding the bytes, never by a filename. HEIC is
 * accepted only when this build's ImageMagick actually has the delegate, so a
 * host without libheif returns a clear photo_format error instead of crashing.
 *
 * @api Called by MediaController.
 */
final class PhotoProcessor
{
    /** docs/specs/photo-uploads.md §7: 15 MB, the GPX-cap precedent. Gated by the caller. */
    public const int MAX_BYTES = 15_728_640;

    private const array ALLOWED_FORMATS = ['JPEG', 'PNG', 'WEBP', 'HEIC', 'HEIF'];
    private const array HEIC_FORMATS = ['HEIC', 'HEIF'];
    private const int MAX_LONG_SIDE = 3840;
    private const int MIN_SHORT_SIDE = 200;

    /**
     * The cap that MATTERS for a decompression bomb: pixels, not bytes.
     *
     * MAX_BYTES bounds what arrives; it does not bound what that decodes to. A
     * few hundred kilobytes of valid, well-formed PNG — one enormous run of
     * identical pixels — expands to gigabytes of pixel buffer, and the worker
     * dies before a single dimension check runs. 50 MP is comfortably above
     * every camera whose full-resolution output fits inside the 15 MB cap, and
     * ~400 MB of decoded RGBA, which the limits below then contain.
     */
    private const int MAX_PIXELS = 50_000_000;

    /**
     * Belt and braces for MAX_PIXELS: the header says how big the image claims
     * to be, and these say how big ImageMagick is willing to go whatever the
     * header claims, so a lying or exotic header fails inside the decoder
     * rather than after it.
     *
     * **Only the stateless, per-image limits belong here.** These are
     * process-global and outlive the call, which is fine for a dimension
     * ceiling — 30,000 px means the same thing on every request forever. It is
     * NOT fine for the pixel-cache budgets (memory / map / disk / area), which
     * are consumed cumulatively by everything the process has decoded: set
     * them here and a long-lived PHP-FPM worker eventually refuses every
     * upload with "unable to create new image", having quietly used its
     * allowance up. That is not theoretical — setting them here turned the
     * test suite red partway through, in exactly that shape, which is the same
     * process lifetime a worker has.
     *
     * So the budgets, the time ceiling and the thread cap live in the deployed
     * policy.xml (web/docker/imagemagick-policy.xml), where ImageMagick applies
     * them per operation instead of per process, and where the image build
     * asserts they are actually in force (docs/specs/photo-uploads.md §7a).
     */
    private const array RESOURCE_LIMITS = [
        \Imagick::RESOURCETYPE_WIDTH => 30_000,
        \Imagick::RESOURCETYPE_HEIGHT => 30_000,
    ];
    private const int LG_WIDTH = 1400;
    private const int SM_WIDTH = 520;
    private const int ORIG_QUALITY = 85;
    private const int LG_QUALITY = 82;
    private const int SM_QUALITY = 80;

    public static function heicSupported(): bool
    {
        return [] !== \Imagick::queryFormats('HEIC');
    }

    /**
     * Apply the process-global decoder limits.
     *
     * Idempotent and cheap, so it runs on every call rather than through a
     * "have we done this yet" flag: the flag would be the bug, since anything
     * that reset the limits (another library, a pooled worker) would leave the
     * next decode unguarded and the flag would say it was fine.
     */
    private static function applyResourceLimits(): void
    {
        foreach (self::RESOURCE_LIMITS as $type => $limit) {
            \Imagick::setResourceLimit($type, $limit);
        }
    }

    public function process(string $bytes, ?string $xmpPacket = null): ProcessedPhoto
    {
        self::applyResourceLimits();

        /* Read the HEADER before the pixels. `pingImageBlob` parses enough to
           answer "how big does this claim to be" without allocating the pixel
           buffer, which is the whole attack: MAX_BYTES bounds the file, and a
           small well-formed file can still declare a gigapixel canvas. Refusing
           here means the bomb never reaches a decoder at all — and the rider
           gets "too large", which is what is actually wrong with it, rather
           than a 502 from a worker that died mid-request. */
        $ping = new \Imagick();
        try {
            $ping->pingImageBlob($bytes);
            $claimedPixels = $ping->getImageWidth() * $ping->getImageHeight();
        } catch (\ImagickException) {
            throw new PhotoRejected('photo_unreadable');
        } finally {
            $ping->clear();
        }
        if ($claimedPixels > self::MAX_PIXELS) {
            // Its own reason, not photo_too_large: a bomb is a few hundred
            // kilobytes, so "that photo is over 15 MB" would be a lie told to
            // somebody whose real problem is the pixel count.
            throw new PhotoRejected('photo_too_many_pixels');
        }

        $image = new \Imagick();
        try {
            $image->readImageBlob($bytes);
        } catch (\ImagickException) {
            throw new PhotoRejected('photo_unreadable');
        }

        try {
            $format = strtoupper($image->getImageFormat());
            if (!\in_array($format, self::ALLOWED_FORMATS, true)) {
                throw new PhotoRejected('photo_format');
            }
            if (\in_array($format, self::HEIC_FORMATS, true) && !self::heicSupported()) {
                throw new PhotoRejected('photo_format');
            }

            // Harvest FIRST — everything below destroys the metadata.
            $takenAt = self::takenAt($image);
            [$gpsLat, $gpsLng] = self::coordinates($image);

            // Bake the orientation flag into the pixels while it still exists.
            // Imagick 3.8 spells this autoOrient(); autoOrientImage() does not
            // exist and is a fatal error.
            $image->autoOrient();

            $width = $image->getImageWidth();
            $height = $image->getImageHeight();
            if (min($width, $height) < self::MIN_SHORT_SIDE) {
                throw new PhotoRejected('photo_too_small');
            }

            // Pixels only, from here on.
            $image->stripImage();

            if (max($width, $height) > self::MAX_LONG_SIDE) {
                // One zero lets ImageMagick keep the aspect ratio; which side is
                // pinned depends on which one is longer.
                $image->resizeImage(
                    $width >= $height ? self::MAX_LONG_SIDE : 0,
                    $height > $width ? self::MAX_LONG_SIDE : 0,
                    \Imagick::FILTER_LANCZOS,
                    1,
                );
            }

            // The packet reaches the two saveable variants; `sm` stays bare.
            $orig = self::encode(clone $image, self::ORIG_QUALITY, $xmpPacket);
            $lg = self::derivative($image, self::LG_WIDTH, self::LG_QUALITY, $xmpPacket);
            $sm = self::derivative($image, self::SM_WIDTH, self::SM_QUALITY, null);

            return new ProcessedPhoto(
                $orig, $lg, $sm,
                $image->getImageWidth(), $image->getImageHeight(),
                $takenAt, $gpsLat, $gpsLng,
            );
        } finally {
            $image->clear();
        }
    }

    /** Narrower than the source, or an untouched copy — never an upscale. */
    private static function derivative(\Imagick $source, int $width, int $quality, ?string $xmpPacket): string
    {
        $copy = clone $source;
        if ($copy->getImageWidth() > $width) {
            $copy->resizeImage($width, 0, \Imagick::FILTER_LANCZOS, 1);
        }

        return self::encode($copy, $quality, $xmpPacket);
    }

    /** Consumes the image it is handed: encodes, then clears it. */
    private static function encode(\Imagick $image, int $quality, ?string $xmpPacket): string
    {
        $image->setImageFormat('webp');
        $image->setImageCompressionQuality($quality);
        if (null !== $xmpPacket) {
            // The one profile that is ours. Written after stripImage(), so it
            // sits alone in a file that inherited nothing.
            $image->setImageProfile('xmp', $xmpPacket);
        }
        $bytes = $image->getImageBlob();
        $image->clear();

        return $bytes;
    }

    /**
     * EXIF DateTimeOriginal, read through ImageMagick's property bridge so one
     * code path covers JPEG and HEIC alike. Published only at month
     * granularity later (docs/specs/photo-uploads.md §5) — the full value is
     * stored because a curator judging a photo benefits from the day.
     */
    private static function takenAt(\Imagick $image): ?\DateTimeImmutable
    {
        $raw = self::property($image, 'exif:DateTimeOriginal');
        if (null === $raw) {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('Y:m:d H:i:s', $raw, new \DateTimeZone('UTC'));

        return false === $parsed ? null : $parsed;
    }

    /**
     * The photo's coordinates, used exactly once (intake computes the distance
     * to the submission pin) and then destroyed. A partial or malformed GPS
     * block yields nulls rather than an exception: a broken EXIF header must
     * never cost a rider their upload.
     *
     * @return array{?float, ?float}
     */
    private static function coordinates(\Imagick $image): array
    {
        $lat = self::degrees(
            self::property($image, 'exif:GPSLatitude'),
            self::property($image, 'exif:GPSLatitudeRef'),
            'S',
        );
        $lng = self::degrees(
            self::property($image, 'exif:GPSLongitude'),
            self::property($image, 'exif:GPSLongitudeRef'),
            'W',
        );

        return (null === $lat || null === $lng) ? [null, null] : [$lat, $lng];
    }

    /** ImageMagick hands GPS back as three rationals: '50/1,29/1,3000/100'. */
    private static function degrees(?string $value, ?string $ref, string $negativeRef): ?float
    {
        if (null === $value) {
            return null;
        }
        $parts = array_map(trim(...), explode(',', $value));
        if (3 !== \count($parts)) {
            return null;
        }

        $components = [];
        foreach ($parts as $part) {
            if (1 !== preg_match('#^(-?\d+)/(\d+)$#', $part, $matches) || '0' === $matches[2]) {
                return null;
            }
            // Cast to float explicitly: an exact division of two ints is an int
            // in PHP, and degrees/minutes/seconds must stay one numeric type.
            $components[] = (float) ((int) $matches[1] / (int) $matches[2]);
        }

        $degrees = $components[0] + $components[1] / 60.0 + $components[2] / 3600.0;
        if (null !== $ref && strtoupper($ref) === $negativeRef) {
            $degrees = -$degrees;
        }

        return round($degrees, 6);
    }

    /**
     * A property the file does not carry reads back as an empty string — or, in
     * ext-imagick's actual behaviour rather than its stub's, as false. It can
     * also throw. All three mean the same thing: absent.
     */
    private static function property(\Imagick $image, string $name): ?string
    {
        try {
            $value = $image->getImageProperty($name);
        } catch (\ImagickException) {
            return null;
        }

        return $value ?: null;
    }
}
