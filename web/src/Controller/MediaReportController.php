<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Media\Entity\MediaUpload;
use App\Media\MediaTakedownCategory;
use App\Media\MediaTakedownService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * "This photo shows me" — the takedown route for people who are IN a photo
 * somebody else uploaded (docs/specs/photo-uploads.md §6c). Open to everyone:
 * GDPR Art. 17 does not require an account here, and most people it protects
 * will not have one.
 *
 * Two rules shape every response:
 *
 * - **No existence oracle.** The form renders for any well-formed uuid without
 *   looking it up, and a POST acknowledges identically whether the photo
 *   exists, is unpublished, is already reported, or was already decided.
 *   Anyone holding a URL learns nothing by filing.
 * - **Queue, don't withhold.** Filing changes nothing visible (the one narrow
 *   exception is decided inside MediaTakedownService::report()). The
 *   acknowledgement therefore promises a look and an answer within a month
 *   (Art. 12(3)) — never an action.
 *
 * Prefix-free and unlocalized like the photo page itself: reachable from a URL
 * baked into files that outlive routing decisions.
 *
 * @api Instantiated by Symfony's router — `@api` tells Psalm this is a live
 *      entry point, not dead code.
 */
final class MediaReportController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaTakedownService $takedowns,
        private readonly RateLimiterFactory $mediaReportLimiter,
        private readonly RateLimiterFactory $mediaReportUrgentLimiter,
    ) {
    }

    #[Route(
        '/photo/{uuid}/report',
        name: 'photo_report',
        requirements: ['uuid' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}'],
        methods: ['GET'],
    )]
    public function form(string $uuid): Response
    {
        // Deliberately no lookup: the form must render the same for a real
        // photo and an invented uuid, or GET alone is the oracle.
        return $this->render('media/report.html.twig', [
            'page_title' => 'media.report.title',
            'page_description' => 'media.report.title',
            'nav_active' => '',
            'photo_uuid' => strtolower($uuid),
            'categories' => MediaTakedownCategory::all(),
            'sent' => false,
        ]);
    }

    #[Route(
        '/photo/{uuid}/report',
        name: 'photo_report_submit',
        requirements: ['uuid' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}'],
        methods: ['POST'],
    )]
    public function submit(string $uuid, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('photo_report', $request->request->get('_token'))) {
            return $this->formWithError($uuid, 'flash.invalid_token', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $category = (string) $request->request->get('category', '');
        $reason = trim((string) $request->request->get('reason', ''));
        $contact = trim((string) $request->request->get('contact', ''));
        $ip = $request->getClientIp() ?? 'unknown';

        if (!MediaTakedownCategory::isValid($category)) {
            return $this->formWithError($uuid, 'media.report.error.category', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ('' === $reason) {
            return $this->formWithError($uuid, 'media.takedown.error.reason_required', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (mb_strlen($reason) > MediaTakedownService::REASON_MAX) {
            return $this->formWithError($uuid, 'media.takedown.error.reason_too_long', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ('' !== $contact && false === filter_var($contact, \FILTER_VALIDATE_EMAIL)) {
            return $this->formWithError($uuid, 'media.report.error.contact', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // The general limiter prices the form; the urgent limiter prices the
        // one lever that changes anything, and both bind before any lookup so
        // a rate-limited caller cannot probe. A 429 reveals only the caller's
        // own request history, never anything about the photo.
        if (!$this->mediaReportLimiter->create('ip-'.$ip)->consume()->isAccepted()) {
            return $this->formWithError($uuid, 'media.report.error.rate_limited', Response::HTTP_TOO_MANY_REQUESTS);
        }
        if (MediaTakedownCategory::autoWithholds($category)
            && !$this->mediaReportUrgentLimiter->create('ip-'.$ip)->consume()->isAccepted()) {
            return $this->formWithError($uuid, 'media.report.error.rate_limited', Response::HTTP_TOO_MANY_REQUESTS);
        }

        $upload = $this->em->find(MediaUpload::class, Uuid::fromString($uuid));
        if (null !== $upload) {
            // Ineligible states are swallowed inside report() — identical
            // acknowledgement, no state change (photo-uploads.md §6c).
            $this->takedowns->report($upload, $category, $reason, '' !== $contact ? $contact : null, $ip);
        }

        // One acknowledgement for every outcome, unknown uuid included.
        return $this->render('media/report.html.twig', [
            'page_title' => 'media.report.title',
            'page_description' => 'media.report.title',
            'nav_active' => '',
            'photo_uuid' => strtolower($uuid),
            'categories' => MediaTakedownCategory::all(),
            'sent' => true,
        ]);
    }

    private function formWithError(string $uuid, string $error, int $status): Response
    {
        return $this->render('media/report.html.twig', [
            'page_title' => 'media.report.title',
            'page_description' => 'media.report.title',
            'nav_active' => '',
            'photo_uuid' => strtolower($uuid),
            'categories' => MediaTakedownCategory::all(),
            'sent' => false,
            'error' => $error,
        ], new Response('', $status));
    }
}
