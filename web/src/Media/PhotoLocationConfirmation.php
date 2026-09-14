<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media;

use App\Catalog\Entity\Item;
use App\Entity\User;
use App\Media\Entity\MediaUpload;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A curator confirms that a rider photo was taken at the pin.
 *
 * The server reads a photo's GPS once at intake, keeps only the distance to
 * the submission pin and strips the rest from the stored file, so a photo that
 * carried no GPS can never be measured afterwards. A scenic view hides such a
 * photo (PhotoValidator answers `hide`). A curator who knows the spot vouches for it here:
 * the upload records who, when and the pin as it stands, the item's gallery
 * entry gains `locationConfirmed: true` and that pin as `confirmedPin`, and the
 * act lands in the media event log. The confirmation puts the camera 0 m from
 * that pin: once the pin moves, the photo counts as taken as far away as the
 * pin now is from it, and hides beyond 250 m until a curator confirms it at
 * the new pin (PhotoValidator::reachM()).
 *
 * @see docs/specs/photo-uploads.md §5g
 * @see docs/specs/scenic-views.md §8
 *
 * @api
 */
final class PhotoLocationConfirmation
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaEventLog $events,
    ) {
    }

    /**
     * Confirm, in one transaction, at the item's pin as it stands. A photo
     * whose confirmation still shows it here keeps its first curator and is
     * not logged again; one the pin has moved out of reach of is confirmed
     * anew.
     *
     * @throws \LogicException when the upload is not an approved photo on an item
     */
    public function confirm(MediaUpload $upload, User $curator): void
    {
        $item = $this->em->find(Item::class, (int) $upload->getItemId());
        $pin = null !== $item ? $this->pinOf($item) : null;
        if (null === $item || null === $pin) {
            throw new \LogicException(\sprintf('Upload %s is not a photo on an item with a pin, so there is no pin to confirm it was taken at.', $upload->getId()->toRfc4122()));
        }
        $place = new PhotoPlace($item->getLetter(), $pin[0], $pin[1]);
        $atThisPin = new PhotoFacts(PhotoOrigin::Rider, MediaDecisionService::LICENSE, null, locationConfirmed: $upload->isLocationConfirmed(), confirmedPin: $upload->getLocationConfirmedPin());
        if ($upload->isLocationConfirmed() && PhotoValidator::verdict($atThisPin, $place)->shows()) {
            return;
        }

        $this->em->wrapInTransaction(function () use ($upload, $curator, $item, $pin): void {
            $curatorId = (int) $curator->getId();
            $upload->confirmLocation($curatorId, new \DateTimeImmutable(), $pin[0], $pin[1]);

            $attributes = $item->getAttributes();
            $changed = false;
            $uuid = $upload->getId()->toRfc4122();
            if (self::isEntryOf($attributes['photo'] ?? null, $uuid) && \is_array($attributes['photo'])) {
                $attributes['photo'] = self::confirmed($attributes['photo'], $pin);
                $changed = true;
            }
            if (\is_array($attributes['photos'] ?? null)) {
                foreach ($attributes['photos'] as $i => $entry) {
                    if (self::isEntryOf($entry, $uuid) && \is_array($entry)) {
                        $attributes['photos'][$i] = self::confirmed($entry, $pin);
                        $changed = true;
                    }
                }
            }
            // setAttributes() moves updated_at, so the catalog payload version moves too.
            if ($changed) {
                $item->setAttributes($attributes);
            }

            $this->events->append($upload->getId(), $curatorId, MediaAction::LocationConfirmed);
            $this->em->flush();
        });
    }

    /**
     * The approved rider photo `$uuid` names, with its item, when it sits on a
     * scenic view and is not under legal hold; null otherwise.
     *
     * @return array{upload: MediaUpload, item: Item}|null
     */
    public function confirmable(string $uuid): ?array
    {
        if (!Uuid::isValid($uuid)) {
            return null;
        }
        $upload = $this->em->find(MediaUpload::class, Uuid::fromString($uuid));
        if (null === $upload || !self::usable($upload) || null === $upload->getItemId()) {
            return null;
        }
        $item = $this->em->find(Item::class, $upload->getItemId());
        if (null === $item || !(new PhotoPlace($item->getLetter(), null, null))->isScenicView()) {
            return null;
        }

        return ['upload' => $upload, 'item' => $item];
    }

    /**
     * The rider photos a scenic view hides, and why, for the curator's block in
     * the map drawer.
     *
     * An entry is listed when it carries an upload `id`, PhotoValidator hides
     * it against the item's pin, and its upload is an approved photo on this
     * item and not under legal hold. The reason is the verdict's on the upload
     * row: `pin_moved` when the photo counted until the pin moved, with
     * `distanceM` the farthest it may now have been taken from the pin
     * (PhotoValidator::reachM()) when it has a distance; `no_gps` when the file
     * carried no position; `too_far` with `distanceM` otherwise. Any other
     * letter hides nothing, so it answers an empty list.
     *
     * @return list<array<string, mixed>>
     */
    public function hiddenPhotos(Item $item): array
    {
        if (!(new PhotoPlace($item->getLetter(), null, null))->isScenicView()) {
            return [];
        }
        $pin = $this->pinOf($item);
        $place = new PhotoPlace($item->getLetter(), $pin[0] ?? null, $pin[1] ?? null);

        $attributes = $item->getAttributes();
        $entries = \is_array($attributes['photos'] ?? null) ? array_values($attributes['photos']) : [];
        if (\array_key_exists('photo', $attributes)) {
            array_unshift($entries, $attributes['photo']);
        }

        $hidden = [];
        $seen = [];
        foreach ($entries as $entry) {
            if (!\is_array($entry) || !\is_string($entry['id'] ?? null) || isset($seen[$entry['id']])) {
                continue;
            }
            $seen[$entry['id']] = true;
            if (PhotoDecision::Hide !== PhotoValidator::verdict(PhotoFacts::fromEntry($entry), $place)->decision || !Uuid::isValid($entry['id'])) {
                continue;
            }
            $upload = $this->em->find(MediaUpload::class, Uuid::fromString($entry['id']));
            if (null === $upload || !self::usable($upload) || $upload->getItemId() !== $item->getId()) {
                continue;
            }

            $photo = ['id' => $entry['id']];
            foreach (['sm', 'lg', 'credit', 'license', 'alt', 'takenAt'] as $key) {
                if (\array_key_exists($key, $entry)) {
                    $photo[$key] = $entry[$key];
                }
            }
            $facts = PhotoFacts::ofUpload($upload);
            $reason = PhotoValidator::verdict($facts, $place)->reason;
            $reach = PhotoValidator::reachM($facts, $place);
            if (PhotoReason::PinMoved === $reason) {
                $photo['reason'] = 'pin_moved';
            } else {
                $photo['reason'] = null === $upload->getGpsDistanceM() ? 'no_gps' : 'too_far';
            }
            if (null !== $reach && 'no_gps' !== $photo['reason']) {
                $photo['distanceM'] = $reach;
            }
            $hidden[] = $photo;
        }

        return $hidden;
    }

    /**
     * The item's pin `[lat, lng]`, the same point CatalogProvider measures a
     * scenic photo against (`ST_PointOnSurface(geom)`), as the row stores it.
     *
     * @return array{0: float, 1: float}|null
     */
    private function pinOf(Item $item): ?array
    {
        $pin = $this->em->getConnection()->fetchAssociative(
            'SELECT ST_Y(ST_PointOnSurface(geom)) AS lat, ST_X(ST_PointOnSurface(geom)) AS lng FROM item WHERE id = :id',
            ['id' => (int) $item->getId()],
        );

        return \is_array($pin) && is_numeric($pin['lat']) && is_numeric($pin['lng']) ? [(float) $pin['lat'], (float) $pin['lng']] : null;
    }

    /**
     * An entry marked confirmed at `$pin`.
     *
     * @param array<array-key, mixed>   $entry
     * @param array{0: float, 1: float} $pin
     *
     * @return array<array-key, mixed>
     */
    private static function confirmed(array $entry, array $pin): array
    {
        $entry['locationConfirmed'] = true;
        $entry['confirmedPin'] = $pin;

        return $entry;
    }

    /**
     * How many rider photos the item shows now and would hide with its pin at
     * `$lat`, `$lng` (PhotoValidator::hiddenByMove()): what the person moving
     * the pin is told before saving.
     */
    public function hiddenByMove(Item $item, float $lat, float $lng): int
    {
        return $this->moveEffect($item, $lat, $lng)['hidden'];
    }

    /**
     * The same count with its reason (PhotoValidator::moveEffect()): what the
     * edit form's warning says.
     *
     * @return array{hidden: int, farthestM: ?int}
     */
    public function moveEffect(Item $item, float $lat, float $lng): array
    {
        $pin = $this->pinOf($item);
        if (null === $pin) {
            return ['hidden' => 0, 'farthestM' => null];
        }

        return PhotoValidator::moveEffect(
            $item->getAttributes(),
            new PhotoPlace($item->getLetter(), $pin[0], $pin[1]),
            new PhotoPlace($item->getLetter(), $lat, $lng),
        );
    }

    /** Approved, published, still stored and not under legal hold. */
    private static function usable(MediaUpload $upload): bool
    {
        return MediaStatus::Approved === $upload->getStatus()
            && $upload->hasPublishedObjects()
            && null === $upload->getObjectsDeletedAt()
            && !$upload->isEscalated();
    }

    /** Matched by the upload id only: a rider entry without one is not something a curator can vouch for. */
    private static function isEntryOf(mixed $entry, string $uuid): bool
    {
        return \is_array($entry) && ($entry['id'] ?? null) === $uuid;
    }
}
