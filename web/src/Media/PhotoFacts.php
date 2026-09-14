<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media;

use App\Media\Commons\CommonsPhotoUsage;
use App\Media\Entity\MediaUpload;

/**
 * What we know about one photo, whichever way it reached us.
 *
 * Built from Commons' own metadata before a download (commons()), from a
 * rider's upload row (ofUpload()), or from a photo entry already stored in an
 * item's `photo` / `photos` attributes (fromEntry()). PhotoValidator decides on
 * these facts alone, so the three sources get one answer.
 *
 * `cameraAt` is where the camera stood, `[lat, lng]`: a Commons file's primary
 * camera coordinate. `distanceM` is a rider upload's measured distance from its
 * GPS position to the submission pin, and `distancePin` that pin, `[lat, lng]`.
 * `locationConfirmed` is a curator's "Taken here" on a rider upload, and
 * `confirmedPin` the pin it was made at. A rider fact with no pin beside it was
 * stored before pins were kept and counts as measured at the current pin. The
 * Commons flags are only known when Commons was asked; a stored entry passed
 * that check when it was linked.
 *
 * @see docs/specs/photo-uploads.md §5h
 *
 * @api
 */
final readonly class PhotoFacts
{
    /**
     * @param array{0: float, 1: float}|null $cameraAt
     * @param array{0: float, 1: float}|null $distancePin
     * @param array{0: float, 1: float}|null $confirmedPin
     */
    public function __construct(
        public PhotoOrigin $origin,
        public ?string $licence,
        public ?string $author,
        public bool $nonFree = false,
        public bool $restricted = false,
        public ?array $cameraAt = null,
        public int|float|null $distanceM = null,
        public bool $locationConfirmed = false,
        public bool $legalHold = false,
        public ?string $uploadId = null,
        public ?array $distancePin = null,
        public ?array $confirmedPin = null,
    ) {
    }

    /**
     * A Commons file as its metadata describes it.
     *
     * @param array{0: float, 1: float}|null $cameraAt
     */
    public static function commons(?string $licence, ?string $author, bool $nonFree, bool $restricted, ?array $cameraAt): self
    {
        return new self(PhotoOrigin::Commons, $licence, $author, $nonFree, $restricted, $cameraAt);
    }

    /**
     * A Commons file as `commons_photo` holds it (credit, license, camera_lat,
     * camera_lng). A row got there through commons(), so its Commons flags
     * were already clear.
     *
     * @param array<string, mixed> $row
     */
    public static function ofCommonsRow(array $row): self
    {
        $lat = $row['camera_lat'] ?? null;
        $lng = $row['camera_lng'] ?? null;

        return self::commons(
            \is_string($row['license'] ?? null) ? $row['license'] : null,
            \is_string($row['credit'] ?? null) ? $row['credit'] : null,
            false,
            false,
            is_numeric($lat) && is_numeric($lng) ? [(float) $lat, (float) $lng] : null,
        );
    }

    /** A rider's upload, from its row. The rider licenses it at upload and may stay unnamed. */
    public static function ofUpload(MediaUpload $upload): self
    {
        return new self(
            PhotoOrigin::Rider,
            MediaDecisionService::LICENSE,
            null,
            distanceM: $upload->getGpsDistanceM(),
            locationConfirmed: $upload->isLocationConfirmed(),
            legalHold: $upload->isEscalated(),
            uploadId: $upload->getId()->toRfc4122(),
            distancePin: $upload->getGpsDistancePin(),
            confirmedPin: $upload->getLocationConfirmedPin(),
        );
    }

    /**
     * A photo entry as an item stores it.
     *
     * An entry with an upload `id` is a rider's; one naming a Commons file in
     * any of its URLs is a Commons file; anything else is an import. Something
     * that is not an entry at all has no licence, so it is refused.
     */
    public static function fromEntry(mixed $entry): self
    {
        if (!\is_array($entry)) {
            return new self(PhotoOrigin::Import, null, null);
        }

        $licence = \is_string($entry['license'] ?? null) ? $entry['license'] : null;
        $credit = \is_string($entry['credit'] ?? null) ? $entry['credit'] : null;

        $id = $entry['id'] ?? null;
        if (\is_string($id) && '' !== $id) {
            $distance = $entry['distanceM'] ?? null;

            return new self(
                PhotoOrigin::Rider,
                $licence,
                $credit,
                distanceM: (\is_int($distance) || \is_float($distance)) && $distance >= 0 ? $distance : null,
                locationConfirmed: true === ($entry['locationConfirmed'] ?? null),
                uploadId: $id,
                distancePin: self::camera($entry['distancePin'] ?? null),
                confirmedPin: self::camera($entry['confirmedPin'] ?? null),
            );
        }

        return new self(
            null === CommonsPhotoUsage::fileOf($entry) ? PhotoOrigin::Import : PhotoOrigin::Commons,
            $licence,
            $credit,
            cameraAt: self::camera($entry['cameraAt'] ?? null),
        );
    }

    /**
     * A `[lat, lng]` pair, or null for anything that is not one. Reads a
     * camera point and a stored pin alike.
     *
     * @return array{0: float, 1: float}|null
     */
    public static function camera(mixed $raw): ?array
    {
        if (!\is_array($raw) || !array_is_list($raw) || 2 !== \count($raw)) {
            return null;
        }
        [$lat, $lng] = $raw;
        if (!(\is_int($lat) || \is_float($lat)) || !(\is_int($lng) || \is_float($lng))) {
            return null;
        }
        if (abs($lat) > 90 || abs($lng) > 180) {
            return null;
        }

        return [(float) $lat, (float) $lng];
    }
}
