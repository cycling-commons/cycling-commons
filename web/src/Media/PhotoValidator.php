<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media;

use App\Contribution\BikeWayReading;

/**
 * Whether a photo may be linked to a place, and whether that place shows it.
 *
 * The one decision, for every way a photo reaches a place and for every page
 * that shows one: a Commons file fetched on demand, harvested, named by
 * Wikidata or localised from a hotlink; a rider's upload approved, re-attached
 * after a takedown or repaired in a gallery; a photo a seed or an import
 * writes; and each display filter (the map payload, the drawer's coverage
 * overlay, the coverage photo endpoint, the best-of preview). Link time and
 * show time ask the same function, so they cannot disagree.
 *
 * The checks, in order:
 *
 * 1. legal hold refuses (photo-uploads.md §6d);
 * 2. a Commons non-free flag or restriction refuses;
 * 3. a licence not on LicenceUrls refuses;
 * 4. a photo that is not the rider's own must name an author: an empty credit,
 *    the bare platform name, or Commons' "no machine-readable author"
 *    boilerplate is no author, and CC BY-SA without a name cannot be complied
 *    with;
 * 5. on a scenic view (letter P) the camera must have stood within
 *    MAX_CAMERA_DISTANCE_M of the pin. A photo shows the view from wherever its
 *    camera stood, so a photo taken elsewhere promises a view the rider will
 *    not get at the pin (owner 2026-09-14). Not knowing is not good enough:
 *    - a Commons or imported photo needs its `cameraAt` and the pin, and is
 *      refused for that place otherwise (the file may suit another place);
 *    - a rider photo counts as here when reachM() is within reach: its GPS
 *      distance, or a curator's "Taken here", which puts the camera 0 m from
 *      the pin as it stood; otherwise it is hidden, not refused, so the entry
 *      stays on the item for a curator to confirm.
 *
 * A rider photo is measured once, to the pin of its submission, and its GPS is
 * then deleted, so a pin that moves later cannot be measured again. The camera
 * stood at most (its distance) + (how far the pin now is from the pin it was
 * measured to) from the pin now (the triangle inequality), and that worst case
 * is what counts (owner 2026-09-15). A pin read with no known position cannot
 * be compared, so it proves nothing.
 *
 * File checks (virus scan, formats, size and pixel limits) are about bytes,
 * not about a place, and stay in PhotoProcessor and the scanner.
 *
 * @see docs/specs/photo-uploads.md §5h
 * @see docs/specs/scenic-views.md §8
 *
 * @api
 */
final class PhotoValidator
{
    /**
     * How far from a scenic pin the camera may have stood.
     *
     * The scenic reach, the same distance a scenic pin may sit from a bike way
     * (BikeWayReading::SCENIC_WITHIN_M): one number for "near enough to count
     * as here" on a scenic view.
     */
    public const int MAX_CAMERA_DISTANCE_M = BikeWayReading::SCENIC_WITHIN_M;

    /**
     * A pin that moved less than this has not moved.
     *
     * Coordinates written back through GeoJSON can shift by a fraction of a
     * metre, and that is rounding, not a new place.
     */
    public const float PIN_STILL_M = 1.0;

    /** Credits that name a platform or state an absence, never a person. */
    public const string NO_AUTHOR = '~^\s*(wikimedia\s+commons)?\s*$|no machine-readable author provided~i';

    public static function verdict(PhotoFacts $photo, PhotoPlace $place): PhotoVerdict
    {
        if ($photo->legalHold) {
            return new PhotoVerdict(PhotoDecision::Refuse, PhotoReason::LegalHold);
        }
        if ($photo->nonFree) {
            return new PhotoVerdict(PhotoDecision::Refuse, PhotoReason::NonFree);
        }
        if ($photo->restricted) {
            return new PhotoVerdict(PhotoDecision::Refuse, PhotoReason::Restricted);
        }
        if (null === $photo->licence || null === LicenceUrls::urlFor(trim($photo->licence))) {
            return new PhotoVerdict(PhotoDecision::Refuse, PhotoReason::Licence);
        }

        $rider = PhotoOrigin::Rider === $photo->origin;
        if (!$rider && (null === $photo->author || 1 === preg_match(self::NO_AUTHOR, $photo->author))) {
            return new PhotoVerdict(PhotoDecision::Refuse, PhotoReason::NoAuthor);
        }

        if (!$place->isScenicView()) {
            return PhotoVerdict::show();
        }

        if ($rider) {
            return self::riderVerdict($photo, $place);
        }

        if (null === $photo->cameraAt || null === $place->lat || null === $place->lng) {
            return new PhotoVerdict(PhotoDecision::Refuse, PhotoReason::CameraUnknown);
        }

        return GpsDistance::metres($place->lat, $place->lng, $photo->cameraAt[0], $photo->cameraAt[1]) <= self::MAX_CAMERA_DISTANCE_M
            ? PhotoVerdict::show()
            : new PhotoVerdict(PhotoDecision::Refuse, PhotoReason::CameraFar);
    }

    /**
     * The farthest a rider photo's camera may have stood from this place's pin,
     * in whole metres rounded up.
     *
     * Two facts can say where the camera stood, and the nearer answer counts:
     *
     * - a measured GPS distance: that distance plus how far the pin now is
     *   from the pin it was measured to;
     * - a curator's "Taken here": the camera stood at the pin as it was then,
     *   0 m from it, so how far the pin now is from that pin.
     *
     * Only where the pin is now counts, in a straight line, never the path it
     * took to get there. Null when neither fact can be measured against this
     * place's pin.
     */
    public static function reachM(PhotoFacts $photo, PhotoPlace $place): ?int
    {
        $reaches = [];
        if (null !== $photo->distanceM) {
            $moved = self::movedM($photo->distancePin, $place);
            if (null !== $moved) {
                $reaches[] = $photo->distanceM + $moved;
            }
        }
        if ($photo->locationConfirmed) {
            $moved = self::movedM($photo->confirmedPin, $place);
            if (null !== $moved) {
                $reaches[] = $moved;
            }
        }

        return [] === $reaches ? null : (int) ceil(min($reaches));
    }

    /**
     * How many rider photos an item shows at `$from` and would no longer show
     * at `$to`: what moving its pin hides until a curator confirms them.
     *
     * The same verdict() at both pins, over the item's `photo` and `photos`
     * entries with an upload `id`. Any other letter hides nothing.
     *
     * @param array<string, mixed> $attributes
     */
    public static function hiddenByMove(array $attributes, PhotoPlace $from, PhotoPlace $to): int
    {
        return self::moveEffect($attributes, $from, $to)['hidden'];
    }

    /**
     * What moving an item's pin from `$from` to `$to` hides, and why.
     *
     * - `hidden`: the rider photos hiddenByMove() counts;
     * - `farthestM`: the farthest their camera may have stood from `$to`
     *   (reachM()), null when none can be measured.
     *
     * @param array<string, mixed> $attributes
     *
     * @return array{hidden: int, farthestM: ?int}
     */
    public static function moveEffect(array $attributes, PhotoPlace $from, PhotoPlace $to): array
    {
        $entries = \is_array($attributes['photos'] ?? null) ? array_values($attributes['photos']) : [];
        if (\array_key_exists('photo', $attributes)) {
            $entries[] = $attributes['photo'];
        }

        $hidden = 0;
        $farthest = null;
        foreach ($entries as $entry) {
            $photo = PhotoFacts::fromEntry($entry);
            if (PhotoOrigin::Rider !== $photo->origin || !self::verdict($photo, $from)->shows() || self::verdict($photo, $to)->shows()) {
                continue;
            }
            ++$hidden;
            $reach = self::reachM($photo, $to);
            if (null !== $reach) {
                $farthest = max($farthest ?? 0, $reach);
            }
        }

        return ['hidden' => $hidden, 'farthestM' => $farthest];
    }

    /**
     * How far the place's pin is from `$from`, a pin a rider fact was recorded
     * at: 0 below PIN_STILL_M, and 0 for a fact with no pin (stored before pins
     * were kept, so it counts as recorded at this pin). Null when the place's
     * pin is not known.
     *
     * @param array{0: float, 1: float}|null $from
     */
    private static function movedM(?array $from, PhotoPlace $place): ?float
    {
        if (null === $from) {
            return 0.0;
        }
        if (null === $place->lat || null === $place->lng) {
            return null;
        }
        $metres = GpsDistance::metres($from[0], $from[1], $place->lat, $place->lng);

        return $metres < self::PIN_STILL_M ? 0.0 : $metres;
    }

    /** A rider photo on a scenic view (check 5 above). */
    private static function riderVerdict(PhotoFacts $photo, PhotoPlace $place): PhotoVerdict
    {
        $reach = self::reachM($photo, $place);
        if (null !== $reach && $reach <= self::MAX_CAMERA_DISTANCE_M) {
            return PhotoVerdict::show();
        }

        // Moved when it counted before: a confirmation made at another pin, or
        // a distance within reach of the pin it was measured to.
        $counted = ($photo->locationConfirmed && null !== self::movedM($photo->confirmedPin, $place))
            || (null !== $reach && null !== $photo->distanceM && $photo->distanceM <= self::MAX_CAMERA_DISTANCE_M);
        if ($counted) {
            return new PhotoVerdict(PhotoDecision::Hide, PhotoReason::PinMoved);
        }

        return new PhotoVerdict(PhotoDecision::Hide, null === $reach ? PhotoReason::CameraUnknown : PhotoReason::CameraFar);
    }

    /**
     * An item's attributes with every photo entry verdict() does not keep taken
     * out, and what was taken out.
     *
     * Not a second decision: each entry is put through verdict() and kept when
     * it shows, or also when it is hidden with `keepHidden` (the link-time
     * shape, where a hidden rider photo stays for a curator). `photo` (the
     * single entry) is removed when dropped; `photos` (the gallery) keeps its
     * entries as a list and is removed when none are left, so a reader sees
     * the same shape as an item that never had one.
     *
     * @param array<string, mixed> $attributes
     *
     * @return array{attributes: array<string, mixed>, dropped: list<array{entry: mixed, verdict: PhotoVerdict}>}
     */
    public static function sift(array $attributes, PhotoPlace $place, bool $keepHidden = false): array
    {
        $dropped = [];
        $keeps = static function (mixed $entry) use ($place, $keepHidden, &$dropped): bool {
            $verdict = self::verdict(PhotoFacts::fromEntry($entry), $place);
            if ($verdict->shows() || ($keepHidden && $verdict->links())) {
                return true;
            }
            $dropped[] = ['entry' => $entry, 'verdict' => $verdict];

            return false;
        };

        if (\array_key_exists('photo', $attributes) && !$keeps($attributes['photo'])) {
            unset($attributes['photo']);
        }

        if (\array_key_exists('photos', $attributes)) {
            $gallery = \is_array($attributes['photos']) ? array_values($attributes['photos']) : [];
            $kept = array_values(array_filter($gallery, $keeps));
            if ([] === $kept) {
                unset($attributes['photos']);
            } else {
                $attributes['photos'] = $kept;
            }
        }

        return ['attributes' => $attributes, 'dropped' => $dropped];
    }
}
