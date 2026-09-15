<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media;

use App\Catalog\Entity\Item;
use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\Entity\Submission;
use App\Entity\User;
use App\Media\Entity\MediaUpload;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Apply a submission decision to its photos, not a second moderation path.
 *
 * Every photo is put through PhotoValidator for the item before it is linked:
 * `refuse` (legal hold) leaves the upload pending and unlinked, `hide` (a
 * scenic view with no usable distance) links it for a curator to confirm,
 * `show` links it.
 *
 * A recommended route's photos take the same decision through
 * applyToRoute(): the route's own approve, reject, done or dismiss.
 *
 * @see docs/specs/photo-uploads.md §5, §5c, §5h, §5i
 *
 * @api
 */
final class MediaDecisionService
{
    public const string LICENSE = 'CC BY-SA 4.0';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaStorage $storage,
        private readonly MediaEventLog $events,
    ) {
    }

    /**
     * @param list<string> $rejectMediaIds
     *
     * @return array{old: mixed, new: list<array<string, mixed>>}|null
     */
    public function apply(
        Submission $submission,
        ?Item $item,
        string $decision,
        User $curator,
        ?string $note,
        array $rejectMediaIds = [],
    ): ?array {
        if ('needs_info' === $decision) {
            return null;
        }

        $uploads = $this->em->getRepository(MediaUpload::class)->findBy([
            'submissionId' => (int) $submission->getId(),
            'status' => MediaStatus::Pending,
        ], ['createdAt' => 'ASC']);
        $place = new PhotoPlace($item?->getLetter() ?? $submission->getLetter(), null, null);
        $attached = $this->decide($uploads, $place, $decision, $curator, $note, $rejectMediaIds, $item?->getId());

        return null === $item ? null : $this->attach($item, $attached);
    }

    /**
     * The same decision for the photos sent for a recommended route: with its
     * proposal (`$suggestionId` null) when the proposal is approved or
     * rejected, or with one photo correction when it is marked done or
     * dismissed. Approved photos land in the route's `attributes.photos`, judged
     * by PhotoValidator as letter R at the route's pin.
     *
     * `approve` and `done` attach; `reject` and `dismissed` reject every photo.
     *
     * @param list<string> $rejectMediaIds
     *
     * @return array{old: mixed, new: list<array<string, mixed>>}|null
     *
     * @see docs/specs/photo-uploads.md §5i
     */
    public function applyToRoute(
        RecommendedRoute $route,
        ?int $suggestionId,
        string $decision,
        User $curator,
        ?string $note,
        array $rejectMediaIds = [],
    ): ?array {
        $uploads = $this->em->getRepository(MediaUpload::class)->findBy([
            'routeId' => (int) $route->getId(),
            'routeSuggestionId' => $suggestionId,
            'status' => MediaStatus::Pending,
        ], ['createdAt' => 'ASC']);
        $rejectAll = \in_array($decision, ['reject', 'dismissed'], true);
        $attached = $this->decide($uploads, PhotoPlace::route(null, null), $rejectAll ? 'reject' : 'approve', $curator, $note, $rejectMediaIds, null);

        return $this->attach($route, $attached);
    }

    /**
     * Put each pending, published upload through PhotoValidator and the
     * decision; answer the gallery entries of the approved ones.
     *
     * @param list<MediaUpload> $uploads
     * @param list<string>      $rejectMediaIds
     *
     * @return list<array<string, mixed>>
     */
    private function decide(array $uploads, PhotoPlace $place, string $decision, User $curator, ?string $note, array $rejectMediaIds, ?int $itemId): array
    {
        // Skip unpublished rows, and every photo PhotoValidator refuses for this
        // place (legal hold, photo-uploads.md §6d): they stay pending.
        $uploads = array_values(array_filter(
            $uploads,
            static fn (MediaUpload $u): bool => $u->hasPublishedObjects()
                && PhotoValidator::verdict(PhotoFacts::ofUpload($u), $place)->links(),
        ));

        $unticked = array_flip(array_map(strval(...), $rejectMediaIds));
        $curatorId = (int) $curator->getId();
        $attached = [];

        foreach ($uploads as $upload) {
            $rejected = 'reject' === $decision || isset($unticked[$upload->getId()->toRfc4122()]);
            if ($rejected) {
                $upload->reject();
                $this->events->append($upload->getId(), $curatorId, MediaAction::Rejected, $note);
                continue;
            }

            $upload->approve($itemId);
            $this->events->append($upload->getId(), $curatorId, MediaAction::Approved, $note);
            $attached[] = $this->describe($upload);
        }

        return $attached;
    }

    /**
     * Append approved entries to a gallery.
     *
     * @param list<array<string, mixed>> $attached
     *
     * @return array{old: mixed, new: list<array<string, mixed>>}|null
     */
    private function attach(Item|RecommendedRoute $holder, array $attached): ?array
    {
        if ([] === $attached) {
            return null;
        }

        $attributes = $holder->getAttributes();
        $old = $attributes['photos'] ?? ($attributes['photo'] ?? null);

        $gallery = self::existingGallery($attributes);
        // Migrate the legacy singular into photos[]; map.js would shadow one if both remain.
        unset($attributes['photo']);
        $attributes['photos'] = [...$gallery, ...$attached];
        $holder->setAttributes($attributes);

        return ['old' => $old, 'new' => $attributes['photos']];
    }

    /**
     * Gallery entry written at approval (takedown reuses this shape).
     *
     * @return array<string, mixed>
     *
     * @see docs/specs/photo-uploads.md §6b
     *
     * @api
     */
    public function describe(MediaUpload $upload): array
    {
        $prefix = $upload->getPathPrefix();
        $bucket = $upload->getStorageBucket();

        $photo = [
            // The upload's own id, so a gallery entry can be matched back to its
            // row without comparing URLs. The URLs move when the key layout or
            // the bucket name does (both have), and every gallery mutation used
            // to match on `sm` - a takedown against a moved photo then matched
            // nothing and silently left the image on the item. The uuid is
            // already visible inside the URL, so publishing it adds nothing.
            'id' => $upload->getId()->toRfc4122(),
            'sm' => $this->storage->url($bucket, $prefix, 'sm'),
            'lg' => $this->storage->url($bucket, $prefix, 'lg'),
            'credit' => $this->credit($upload),
            'license' => self::LICENSE,
            // Metres from the photo's GPS position to the submission pin, or
            // null when it carried none. Only the distance: the position itself
            // is dropped at intake (photo-uploads.md §3a). A scenic view shows
            // the photo only when PhotoValidator::reachM() is within reach.
            'distanceM' => $upload->getGpsDistanceM(),
        ];
        // The pin that distance was measured to, `[lat, lng]`, when there is a
        // distance: a pin that moves later adds its move to the distance
        // (PhotoValidator::reachM()). @see docs/specs/photo-uploads.md §5g
        $distancePin = $upload->getGpsDistancePin();
        if (null !== $distancePin) {
            $photo['distancePin'] = $distancePin;
        }
        // Only when a curator confirmed the photo was taken at the pin; a
        // scenic view then shows it whatever the distance, while the pin
        // stays where it was confirmed (`confirmedPin`). @see docs/specs/photo-uploads.md §5g
        if ($upload->isLocationConfirmed()) {
            $photo['locationConfirmed'] = true;
            $confirmedPin = $upload->getLocationConfirmedPin();
            if (null !== $confirmedPin) {
                $photo['confirmedPin'] = $confirmedPin;
            }
        }
        // Only when there is one. An absent key lets the render side fall back
        // to the item's name, which is better than an empty alt and much better
        // than a filename. @see docs/specs/photo-uploads.md §5e
        $alt = $upload->getAltText();
        if (null !== $alt) {
            $photo['alt'] = $alt;
        }
        // Month granularity only. @see docs/specs/photo-uploads.md §5
        $takenAt = $upload->getTakenAt();
        if (null !== $takenAt) {
            $photo['takenAt'] = $takenAt->format('Y-m');
        }

        return $photo;
    }

    /**
     * Is this gallery entry the one this upload wrote?
     *
     * The single place that answers it, so takedown, escalation and disposal
     * can never drift apart. Prefers the recorded id; falls back to the `sm`
     * URL for entries written before the id existed. That fallback goes once
     * `app:media:repair-galleries` has run everywhere - it is a migration
     * bridge, not a shape to tolerate.
     *
     * @see docs/specs/photo-uploads.md §6b
     *
     * @api
     */
    public function isEntryFor(mixed $photo, MediaUpload $upload): bool
    {
        if (!\is_array($photo)) {
            return false;
        }

        $id = $photo['id'] ?? null;
        if (\is_string($id) && '' !== $id) {
            return $id === $upload->getId()->toRfc4122();
        }

        return ($photo['sm'] ?? null) === $this->describe($upload)['sm'];
    }

    /** Name only when the rider chose a public profile. */
    private function credit(MediaUpload $upload): string
    {
        $userId = $upload->getUserId();
        if (null === $userId) {
            return '';
        }
        $user = $this->em->find(User::class, $userId);

        return (null !== $user && $user->isPublicProfile()) ? $user->getDisplayName() : '';
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @return list<array<string, mixed>>
     */
    private static function existingGallery(array $attributes): array
    {
        $photos = $attributes['photos'] ?? null;
        if (\is_array($photos)) {
            return array_values(array_filter($photos, is_array(...)));
        }
        $single = $attributes['photo'] ?? null;

        return \is_array($single) ? [$single] : [];
    }
}
