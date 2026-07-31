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
    private const int LG_WIDTH = 1400;
    private const int SM_WIDTH = 520;
    private const int ORIG_QUALITY = 85;
    private const int LG_QUALITY = 82;
    private const int SM_QUALITY = 80;

    public static function heicSupported(): bool
    {
        return [] !== \Imagick::queryFormats('HEIC');
    }

    public function process(string $bytes, ?string $xmpPacket = null): ProcessedPhoto
    {
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
            $components[] = (int) $matches[1] / (int) $matches[2];
        }

        $degrees = $components[0] + $components[1] / 60 + $components[2] / 3600;
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
