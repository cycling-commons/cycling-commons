<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Media\ConsentMissing;
use App\Media\ConsentService;
use App\Media\ContinentResolver;
use App\Media\Entity\MediaUpload;
use App\Media\MediaAction;
use App\Media\MediaConsent;
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
 * The rider media API (docs/specs/photo-uploads.md §3, §4): consent first, then
 * uploads. Unlocalized JSON endpoints like RouteCommunityController, with the
 * same in-controller 401 — an API client must never be 302-redirected to a
 * login page.
 *
 * Consent is the gate, and the gate is here, not in the browser. The wizard
 * locks its upload controls until the server acknowledges a stored consent
 * record; that is sequencing for the rider's benefit. The guarantee is that
 * every write path below refuses without a consent record that exists, belongs
 * to the caller, and matches the current wording version.
 *
 * @api Instantiated by Symfony's router; called by assets/contribute/media-upload.js.
 */
final class MediaController extends AbstractController
{
    public const string CSRF_INTENTION = 'media-upload';

    /**
     * The declared types the endpoint will accept without decoding anything.
     *
     * A sniff of the leading bytes (finfo, no decoder involved), not a
     * filename, and deliberately not proof: the real answer comes from the
     * worker's decode. It is here only so the obvious wrong thing - a PDF, a
     * ZIP, a video - is refused while the rider is still watching, instead of
     * costing a quarantine write, a scan and a message round trip to say the
     * same thing a minute later.
     */
    private const array SNIFFED_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif', 'image/avif'];

    public function __construct(
        private readonly ConsentService $consent,
        private readonly MediaStorage $storage,
        private readonly ContinentResolver $continents,
        private readonly MediaEventLog $events,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route('/media/token', name: 'media_token', methods: ['GET'])]
    public function token(CsrfTokenManagerInterface $csrf): JsonResponse
    {
        $this->requireUser();

        return $this->json(['token' => $csrf->getToken(self::CSRF_INTENTION)->getValue()]);
    }

    /**
     * The wizard's cross-visit bootstrap: has this rider already granted the
     * current contract? A null consentId is the honest default and the only
     * answer any error can produce.
     */
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

    /** One consent act, one immutable row. Never an upsert (§3 ledger). */
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
     * One photo per request, and the request no longer processes it
     * (docs/specs/media-storage-architecture.md §3).
     *
     * Everything this endpoint does is something the web tier can do safely:
     * identity, token, consent, the byte cap, the limiter, a type sniff, a
     * write of the RAW bytes into the private bucket, one row, one message.
     * It never decodes an image and never publishes anything - it physically
     * cannot scan, and a tier that cannot scan must not be allowed to publish.
     *
     * The order of the checks is still deliberate, and for the same reason as
     * before: an abusive caller must not get the server to spend anything on
     * their behalf, and now the cheapest gate of all - never touching the
     * pixels - is the default.
     */
    #[Route('/media/photos', name: 'media_photos_upload', methods: ['POST'])]
    public function upload(Request $request, RateLimiterFactoryInterface $mediaUploadLimiter): JsonResponse
    {
        $user = $this->requireUser();
        $this->requireCsrf($request);

        try {
            $consent = $this->consent->assertValid($user, $request->request->get('consentId'));
        } catch (ConsentMissing) {
            // Fail-closed: no valid record, no upload — whatever the UI believes.
            return $this->json(['error' => 'consent_required'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $file = $request->files->get('photo');
        if (!$file instanceof UploadedFile) {
            return $this->json(['error' => 'missing_file'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        // A file that blew past PHP's own ini/form limit arrives as an error
        // rather than as bytes. That is still "too large" and must be said so:
        // telling a rider no photo arrived when a 30 MB one did is a lie the
        // rider cannot act on.
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

        // finfo on the leading bytes, never the filename, and never a decode.
        if (!\in_array((string) $file->getMimeType(), self::SNIFFED_TYPES, true)) {
            return $this->json(['error' => 'photo_format'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // The id exists before the bytes do: the rights packet names the
        // photo's own page, so the uuid has to be minted first
        // (docs/specs/photo-uploads.md §1.3c). The packet itself is written by
        // the worker, which is where the encoding now happens.
        $mediaId = Uuid::v4();

        /* Shard resolution (docs/specs/photo-uploads.md §3): the wizard's pin
           wins, because it is where the rider says the place IS. The photo's
           own coordinates are a second VERIFICATION (the worker records the
           EXIF-to-pin distance), never the address. The message carries the
           pin so the worker can finish that verification once it has the
           decode, and shardFor() is asked BEFORE the row exists because the
           row records where the bytes actually went. */
        $pinLat = $this->coordinate($request, 'lat');
        $pinLng = $this->coordinate($request, 'lng');
        /* The pin is REQUIRED (owner 2026-08-18): every photo is uploaded for
           a located place, so a missing pin is a broken caller, not a case to
           absorb, and a photo that cannot be placed is refused outright
           (location_unresolvable below). */
        if (null === $pinLat || null === $pinLng || abs($pinLat) > 90.0 || abs($pinLng) > 180.0) {
            return $this->json(['error' => 'missing_location'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $continent = $this->continents->resolve($pinLat, $pinLng);
        if (null === $continent) {
            /* A pin in the sea or outside every onboarded region belongs to
               no continent, and a photo we cannot place is a photo we do not
               accept (owner 2026-08-18). Never a default shard. */
            return $this->json(['error' => 'location_unresolvable'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            [$shard, $bucket] = $this->storage->activeFor($continent);
        } catch (ShardUnavailable) {
            /* A cleanly resolved continent with no provisioned bucket refuses
               the upload (owner 2026-08-18: "storage must fail") instead of
               borrowing another continent's bucket. Provisioning the bucket
               is what turns this refusal off. */
            return $this->json(['error' => 'storage_unavailable'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $upload = MediaUpload::quarantined(
            $mediaId,
            (int) $user->getId(),
            $consent->getId(),
            $continent,
            $shard,
            $bucket,
            (int) $file->getSize(),
        );

        /* Bytes into the private bucket FIRST, row second. The other order
           leaves a row promising a scan of an object that was never written,
           and the handler's "still quarantined?" guard would read that as a
           release that already happened. */
        $this->storage->writeQuarantine($upload->getQuarantineKey(), (string) file_get_contents($file->getPathname()));
        $this->em->persist($upload);
        $this->events->append($upload->getId(), (int) $user->getId(), MediaAction::Uploaded);
        $this->em->flush();

        /* AFTER the flush, never before: the async transport is a Redis stream
           a consumer is already tailing, so a message dispatched ahead of the
           commit races a worker that would not find the row. */
        $this->bus->dispatch(new ScanAndReleaseUpload($mediaId->toRfc4122(), $pinLat, $pinLng));

        return $this->json($this->state($upload), Response::HTTP_ACCEPTED);
    }

    /**
     * What became of one upload - the wizard's poll while the worker runs
     * (docs/specs/photo-uploads.md §4).
     *
     * Owner-scoped: a rider may ask about their own upload and nothing else.
     * A stranger's id answers 404 rather than 403, because "that is not yours"
     * is itself an answer about somebody else's photo.
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
     * The one shape both the upload response and the poll answer with, so the
     * wizard has a single thing to read.
     *
     * `ready` is derived from the objects existing, not from the status
     * column: the release gate is physical
     * (docs/specs/media-storage-architecture.md §3), and a client told "ready"
     * by a flag is a client that can be told it by a flag alone.
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
            $state['sm'] = $this->storage->url($upload->getStorageShard(), $upload->getPathPrefix(), 'sm');
            $state['lg'] = $this->storage->url($upload->getStorageShard(), $upload->getPathPrefix(), 'lg');
        } elseif (MediaStatus::Rejected === $upload->getStatus()) {
            // The worker refused it. The reason lives in the event log; the
            // rider gets the one word that tells them to try another photo.
            $state['error'] = 'photo_rejected';
        }

        return $state;
    }

    private function coordinate(Request $request, string $key): ?float
    {
        $raw = $request->request->get($key);

        return is_numeric($raw) ? (float) $raw : null;
    }

    /**
     * A fully authenticated ROLE_USER, or a clean 401 — the same gate
     * RouteCommunityController uses, and for the same reason. Also catches
     * 2FA-in-progress tokens, which lack ROLE_USER.
     */
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
