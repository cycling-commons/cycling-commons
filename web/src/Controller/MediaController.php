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
use App\Media\MediaStorage;
use App\Media\PhotoProcessor;
use App\Media\PhotoRejected;
use App\Media\XmpRights;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
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

    public function __construct(
        private readonly ConsentService $consent,
        private readonly PhotoProcessor $processor,
        private readonly MediaStorage $storage,
        private readonly ContinentResolver $continents,
        private readonly XmpRights $rights,
        private readonly MediaEventLog $events,
        private readonly EntityManagerInterface $em,
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
     * One photo per request. The order of the checks is deliberate: identity,
     * then token, then consent, then the cheap byte-count gate, then the
     * limiter, and only then the expensive decode — so an abusive caller never
     * gets the server to spend an Imagick decode on their behalf.
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

        // The id exists before the bytes do: the rights packet names the
        // photo's own page, so the uuid has to be minted first
        // (docs/specs/photo-uploads.md §1.3c).
        $mediaId = Uuid::v4();

        try {
            $processed = $this->processor->process(
                (string) file_get_contents($file->getPathname()),
                $this->rights->forPhoto($mediaId),
            );
        } catch (PhotoRejected $rejected) {
            return $this->json(['error' => $rejected->reason], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Shard resolution (docs/specs/photo-uploads.md §3): the wizard's pin
        // wins, because it is where the rider says the place IS; the photo's own
        // coordinates are the fallback; then the configured default.
        $pinLat = $this->coordinate($request, 'lat');
        $pinLng = $this->coordinate($request, 'lng');
        $continent = (null !== $pinLat && null !== $pinLng)
            ? $this->continents->resolve($pinLat, $pinLng)
            : $this->continents->resolve($processed->gpsLat, $processed->gpsLng);

        $upload = new MediaUpload(
            $mediaId,
            (int) $user->getId(),
            $consent->getId(),
            $continent,
            $processed->width,
            $processed->height,
            \strlen($processed->orig),
            $processed->takenAt,
            $processed->gpsLat,
            $processed->gpsLng,
        );

        $this->storage->store($continent, $upload->getPathPrefix(), $processed);
        $this->em->persist($upload);
        $this->events->append($upload->getId(), (int) $user->getId(), MediaAction::Uploaded);
        $this->em->flush();

        return $this->json([
            'id' => $upload->getId()->toRfc4122(),
            'sm' => $this->storage->url($continent, $upload->getPathPrefix(), 'sm'),
            'lg' => $this->storage->url($continent, $upload->getPathPrefix(), 'lg'),
        ]);
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
