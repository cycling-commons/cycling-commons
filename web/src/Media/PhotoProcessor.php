<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media;

/**
 * Decode → harvest → orient → strip → encode → authored licence packet.
 *
 * @see docs/specs/photo-uploads.md §3a
 *
 * @api
 */
final class PhotoProcessor
{
    /** 15 MB input cap. @see docs/specs/photo-uploads.md §7 */
    public const int MAX_BYTES = 15_728_640;

    private const array ALLOWED_FORMATS = ['JPEG', 'PNG', 'WEBP', 'HEIC', 'HEIF'];
    private const array HEIC_FORMATS = ['HEIC', 'HEIF'];
    private const int MAX_LONG_SIDE = 3840;
    private const int MIN_SHORT_SIDE = 200;

    /** Pixel cap against decompression bombs (bytes do not bound decoded size). */
    private const int MAX_PIXELS = 50_000_000;

    /**
     * Per-image dimension ceilings only. Pixel-cache budgets live in policy.xml.
     *
     * @see docs/specs/photo-uploads.md §7a
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

    /** Re-apply process-global decoder limits on every call. */
    private static function applyResourceLimits(): void
    {
        foreach (self::RESOURCE_LIMITS as $type => $limit) {
            \Imagick::setResourceLimit($type, $limit);
        }
    }

    public function process(string $bytes, ?string $xmpPacket = null): ProcessedPhoto
    {
        self::applyResourceLimits();

        // Header first: refuse a claimed gigapixel canvas before allocating pixels.
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

            // Harvest first — everything below destroys the metadata.
            $takenAt = self::takenAt($image);
            [$gpsLat, $gpsLng] = self::coordinates($image);

            // Imagick 3.8: autoOrient(); autoOrientImage() does not exist.
            $image->autoOrient();

            $width = $image->getImageWidth();
            $height = $image->getImageHeight();
            if (min($width, $height) < self::MIN_SHORT_SIDE) {
                throw new PhotoRejected('photo_too_small');
            }

            $image->stripImage();

            if (max($width, $height) > self::MAX_LONG_SIDE) {
                $image->resizeImage(
                    $width >= $height ? self::MAX_LONG_SIDE : 0,
                    $height > $width ? self::MAX_LONG_SIDE : 0,
                    \Imagick::FILTER_LANCZOS,
                    1,
                );
            }

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
            $image->setImageProfile('xmp', $xmpPacket);
        }
        $bytes = $image->getImageBlob();
        $image->clear();

        return $bytes;
    }

    /**
     * EXIF DateTimeOriginal; published later at month granularity.
     *
     * @see docs/specs/photo-uploads.md §5
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
     * GPS for one distance computation, then destroyed. Malformed EXIF → nulls.
     *
     * @return array{?float, ?float}
     *
     * @see docs/specs/photo-uploads.md §3
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

    /** ImageMagick GPS as three rationals: '50/1,29/1,3000/100'. */
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
            // Exact int division stays int in PHP; degrees must be float.
            $components[] = (float) ((int) $matches[1] / (int) $matches[2]);
        }

        $degrees = $components[0] + $components[1] / 60.0 + $components[2] / 3600.0;
        if (null !== $ref && strtoupper($ref) === $negativeRef) {
            $degrees = -$degrees;
        }

        return round($degrees, 6);
    }

    /** Missing property: empty string, false, or throw — all mean absent. */
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
