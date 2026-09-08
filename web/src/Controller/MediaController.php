<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\Entity\Item;
use App\Contribution\CatalogContributionService;
use App\Entity\User;
use App\Media\ConsentMissing;
use App\Media\ConsentService;
use App\Media\ContinentResolver;
use App\Media\Entity\MediaUpload;
use App\Media\MediaAction;
use App\Media\MediaConsent;
use App\Media\MediaDecisionService;
use App\Media\MediaEventLog;
use App\Media\MediaStatus;
use App\Media\MediaStorage;
use App\Media\Message\ScanAndReleaseUpload;
use App\Media\PhotoProcessor;
use App\Media\ShardUnavailable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Consent-gated photo upload. 401 not 302.
 *
 * @see docs/specs/photo-uploads.md §3
 * @see docs/specs/media-storage-architecture.md §3
 *
 * @api
 */
final class MediaController extends AbstractController
{
    public const string CSRF_INTENTION = 'media-upload';

    /** Leading-byte sniff only — worker decode is the real gate. */
    /** Long enough for a useful sentence, short enough to stay out of the way. */
    private const int ALT_MAX = 300;

    private const array SNIFFED_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif', 'image/avif'];

    public function __construct(
        private readonly ConsentService $consent,
        private readonly MediaStorage $storage,
        private readonly ContinentResolver $continents,
        private readonly MediaEventLog $events,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly MediaDecisionService $decisions,
        private readonly CatalogContributionService $contributions,
    ) {
    }

    #[Route('/media/token', name: 'media_token', methods: ['GET'])]
    public function token(CsrfTokenManagerInterface $csrf): JsonResponse
    {
        $this->requireUser();

        return $this->json(['token' => $csrf->getToken(self::CSRF_INTENTION)->getValue()]);
    }

    /** Current consent, or null. */
    #[Route('/media/consent/current', name: 'media_consent_current', methods: ['GET'])]
    public function currentConsent(): JsonResponse
    {
        $record = $this->consent->current($this->requireUser());

        return $this->json([
            'consentId' => $record?->getId()->toRfc4122(),
            'consentedAt' => $record?->getConsentedAt()->format(\DATE_ATOM),
            'version' => MediaConsent::VERSION,
            'contract' => $this->consent->contractText(),
        ]);
    }

    /** One consent act, one immutable row. Never an upsert.
     *
     * @see docs/specs/photo-uploads.md §3
     */
    #[Route('/media/consent', name: 'media_consent', methods: ['POST'])]
    public function grantConsent(Request $request): JsonResponse
    {
        $user = $this->requireUser();
        $this->requireCsrf($request);

        $record = $this->consent->record($user);

        return $this->json([
            'consentId' => $record->getId()->toRfc4122(),
            'consentedAt' => $record->getConsentedAt()->format(\DATE_ATOM),
        ]);
    }

    /**
     * Quarantine raw bytes; never decode or publish on the web tier.
     *
     * @see docs/specs/media-storage-architecture.md §3
     */
    #[Route('/media/photos', name: 'media_photos_upload', methods: ['POST'])]
    public function upload(Request $request, RateLimiterFactoryInterface $mediaUploadLimiter): JsonResponse
    {
        $user = $this->requireUser();
        $this->requireCsrf($request);

        try {
            $consent = $this->consent->assertValid($user, $request->request->get('consentId'));
        } catch (ConsentMissing) {
            // docs/specs/photo-uploads.md §3 — fail-closed: no consent, no upload.
            return $this->json(['error' => 'consent_required'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $file = $request->files->get('photo');
        if (!$file instanceof UploadedFile) {
            return $this->json(['error' => 'missing_file'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (\in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)
            || $file->getSize() > PhotoProcessor::MAX_BYTES
        ) {
            return $this->json(['error' => 'photo_too_large'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!$file->isValid()) {
            return $this->json(['error' => 'missing_file'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!$mediaUploadLimiter->create('user-'.(string) $user->getId())->consume()->isAccepted()) {
            return $this->json(['error' => 'rate_limited'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        if (!\in_array((string) $file->getMimeType(), self::SNIFFED_TYPES, true)) {
            return $this->json(['error' => 'photo_format'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // docs/specs/photo-uploads.md §1 — uuid first so the rights packet can name the photo page.
        $mediaId = Uuid::v4();

        // docs/specs/photo-uploads.md §3 — pin locates the shard; EXIF is verification only.
        $pinLat = $this->coordinate($request, 'lat');
        $pinLng = $this->coordinate($request, 'lng');
        if (null === $pinLat || null === $pinLng || abs($pinLat) > 90.0 || abs($pinLng) > 180.0) {
            return $this->json(['error' => 'missing_location'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $continent = $this->continents->resolve($pinLat, $pinLng);
        if (null === $continent) {
            // docs/specs/photo-uploads.md §1 — no default continent.
            return $this->json(['error' => 'location_unresolvable'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $bucket = $this->storage->bucketFor($continent);
        } catch (ShardUnavailable) {
            // docs/specs/photo-uploads.md §1 — never borrow another continent's bucket.
            return $this->json(['error' => 'storage_unavailable'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $upload = MediaUpload::quarantined(
            $mediaId,
            (int) $user->getId(),
            $consent->getId(),
            $continent,
            $bucket,
            (int) $file->getSize(),
        );

        // docs/specs/media-storage-architecture.md §3 — bytes first, row second.
        $this->storage->writeQuarantine($upload->getQuarantineKey(), (string) file_get_contents($file->getPathname()));
        $this->em->persist($upload);
        $this->events->append($upload->getId(), (int) $user->getId(), MediaAction::Uploaded);
        $this->em->flush();

        // After flush: dispatch before commit races a worker that cannot find the row.
        $this->bus->dispatch(new ScanAndReleaseUpload($mediaId->toRfc4122(), $pinLat, $pinLng));

        return $this->json($this->state($upload), Response::HTTP_ACCEPTED);
    }

    /**
     * Owner-scoped poll: stranger's id is 404, not 403 (IDOR).
     *
     * @see docs/specs/photo-uploads.md §4
     */
    #[Route('/media/photos/{id}', name: 'media_photos_state', methods: ['GET'])]
    public function photoState(string $id): JsonResponse
    {
        $user = $this->requireUser();
        $upload = Uuid::isValid($id) ? $this->em->find(MediaUpload::class, Uuid::fromString($id)) : null;
        if (null === $upload || $upload->getUserId() !== (int) $user->getId()) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->state($upload));
    }

    /**
     * Save what somebody who cannot see the photo needs to know.
     *
     * Its own endpoint rather than a field on the upload, because the upload
     * POSTs the moment a file is chosen and the rider has not typed anything
     * yet. Sending it separately also means a slow typist never blocks the scan
     * queue, and a description can be fixed after the fact.
     *
     * Owner only. A curator edits it from the moderation queue instead, where
     * they are already looking at the picture.
     *
     * @see docs/specs/photo-uploads.md §5e
     */
    #[Route('/media/photos/{id}/alt', name: 'media_photos_alt', methods: ['POST'])]
    public function altText(string $id, Request $request): JsonResponse
    {
        $user = $this->requireUser();
        $this->requireCsrf($request);

        $upload = Uuid::isValid($id) ? $this->em->find(MediaUpload::class, Uuid::fromString($id)) : null;
        // The uploader, or a curator: a curator's word applies at once here as
        // it does on every other edit (moderation-and-contribution.md 1.6;
        // owner 2026-09-08: "has nothing to do with who added the photo").
        // Anybody else gets the same 404 an unknown id gets, so the route is
        // not an oracle for whose photo this is.
        if (null === $upload || ($upload->getUserId() !== (int) $user->getId() && !$this->isGranted('ROLE_CURATOR'))) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        $alt = $request->request->get('alt');
        $alt = \is_string($alt) ? $alt : null;
        // A description longer than this is not a description; the cap keeps it
        // out of the gallery JSON, which rides in cached tiles.
        if (null !== $alt && mb_strlen($alt) > self::ALT_MAX) {
            return $this->json(['error' => 'alt_too_long'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $upload->setAltText($alt);
        // The description lives in TWO places, and writing only one of them was
        // the bug: the upload row, and a copy inside the item's `photos`
        // gallery, which is what the map, the tiles and the wizard's review
        // step all read. Editing an approved photo's description used to update
        // the row and leave the gallery saying what it said at approval
        // (owner, 2026-08-30: "again the text for the already existing image is
        // not visible"). The gallery copy is not a cache to be rebuilt: it
        // rides inside cached tiles, so it has to be written here.
        $this->syncGalleryAlt($upload, $alt);
        $this->em->flush();

        return $this->json(['alt' => $upload->getAltText()]);
    }

    /**
     * Suggest a description for a photograph somebody else uploaded.
     *
     * The owner-only endpoint above writes straight through. Everybody else
     * lands here, and their words go into the same review queue as every other
     * edit on that place: a curator sees the suggestion beside the rest and
     * approving it writes both copies (owner, 2026-08-30). No new moderation
     * mechanic, which is the house rule.
     *
     * The OWNER is deliberately refused here rather than quietly handled: they
     * have a route that takes effect immediately, and sending their own words
     * to a queue would be a worse answer, not a kinder one.
     *
     * @see docs/specs/photo-uploads.md §5e
     */
    #[Route('/media/photos/{id}/alt-suggestion', name: 'media_photos_alt_suggest', methods: ['POST'])]
    public function suggestAltText(string $id, Request $request): JsonResponse
    {
        $user = $this->requireUser();
        $this->requireCsrf($request);

        $upload = Uuid::isValid($id) ? $this->em->find(MediaUpload::class, Uuid::fromString($id)) : null;
        if (null === $upload || $upload->getUserId() === (int) $user->getId()) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        $itemId = $upload->getItemId();
        $item = null === $itemId ? null : $this->em->find(Item::class, $itemId);
        // A picture nobody has approved onto a place yet has no item to file an
        // edit against, and nothing public to correct either.
        if (!$item instanceof Item) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        $alt = $request->request->get('alt');
        $alt = \is_string($alt) ? trim($alt) : null;
        if (null !== $alt && mb_strlen($alt) > self::ALT_MAX) {
            return $this->json(['error' => 'alt_too_long'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $submission = $this->contributions->suggestPhotoAlt($item, $upload, '' === $alt ? null : $alt, $user);

        return $this->json(['submission' => $submission->getId()]);
    }

    /**
     * Carry a changed description into the item's published gallery entry.
     *
     * Only an approved photo has one, and only the entry that IS this upload is
     * touched: `isEntryFor()` matches on the uuid, falling back to the small
     * URL for entries written before ids were stored.
     */
    private function syncGalleryAlt(MediaUpload $upload, ?string $alt): void
    {
        $itemId = $upload->getItemId();
        if (null === $itemId) {
            return;
        }

        $item = $this->em->find(Item::class, $itemId);
        if (!$item instanceof Item) {
            return;
        }

        $attributes = $item->getAttributes();
        $photos = $attributes['photos'] ?? null;
        if (!\is_array($photos)) {
            return;
        }

        $changed = false;
        foreach ($photos as $index => $photo) {
            if (!$this->decisions->isEntryFor($photo, $upload)) {
                continue;
            }
            if (null === $alt || '' === $alt) {
                // An emptied description leaves no key at all, which is what
                // lets the render side fall back to the place's name rather
                // than to an empty alt.
                unset($photos[$index]['alt']);
            } else {
                $photos[$index]['alt'] = $alt;
            }
            $changed = true;
        }

        if ($changed) {
            $attributes['photos'] = array_values($photos);
            $item->setAttributes($attributes);
        }
    }

    /**
     * @see docs/specs/media-storage-architecture.md §3
     *
     * @return array<string, mixed>
     */
    private function state(MediaUpload $upload): array
    {
        $state = [
            'id' => $upload->getId()->toRfc4122(),
            'status' => $upload->getStatus()->value,
            'ready' => $upload->hasPublishedObjects(),
        ];
        if ($upload->hasPublishedObjects()) {
            $state['sm'] = $this->storage->url($upload->getStorageBucket(), $upload->getPathPrefix(), 'sm');
            $state['lg'] = $this->storage->url($upload->getStorageBucket(), $upload->getPathPrefix(), 'lg');
        } elseif (MediaStatus::Rejected === $upload->getStatus()) {
            $state['error'] = 'photo_rejected';
        }

        return $state;
    }

    private function coordinate(Request $request, string $key): ?float
    {
        $raw = $request->request->get($key);

        return is_numeric($raw) ? (float) $raw : null;
    }

    /** ROLE_USER, or a clean 401 (never a login redirect). */
    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$this->isGranted('ROLE_USER') || !$user instanceof User) {
            throw new HttpException(Response::HTTP_UNAUTHORIZED, 'authentication_required');
        }

        return $user;
    }

    private function requireCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF_INTENTION, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
