<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Media\ConsentService;
use App\Media\MediaConsent;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

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
