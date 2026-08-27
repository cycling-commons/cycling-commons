<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Media\Entity\MediaUpload;
use App\Media\MediaTakedownCategory;
use App\Media\MediaTakedownService;
use App\Media\UrgentWithholdBreaker;
use App\Security\ProofOfWork;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Third-party photo report: no existence oracle; queue, don't withhold.
 *
 * @see docs/specs/photo-uploads.md §6c
 *
 * @api
 */
final class MediaReportController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaTakedownService $takedowns,
        private readonly RateLimiterFactory $mediaReportLimiter,
        private readonly RateLimiterFactory $mediaReportUrgentLimiter,
        private readonly UrgentWithholdBreaker $breaker,
        private readonly ProofOfWork $proofOfWork,
    ) {
    }

    /**
     * Always issue PoW — gating on breaker-open would leak that a flood is working.
     *
     * @see docs/specs/photo-uploads.md §6c
     *
     * @return array<string, mixed>
     */
    private function context(string $uuid, bool $sent, ?string $error = null): array
    {
        return [
            'page_title' => 'media.report.title',
            'page_description' => 'media.report.title',
            'nav_active' => '',
            'photo_uuid' => strtolower($uuid),
            'categories' => MediaTakedownCategory::all(),
            'sent' => $sent,
            'error' => $error,
            'pow_challenge' => $this->proofOfWork->issue(new \DateTimeImmutable()),
            'pow_difficulty' => ProofOfWork::DIFFICULTY,
            'urgent_category' => MediaTakedownCategory::IntimateOrChild,
        ];
    }

    #[Route(
        '/photo/{uuid}/report',
        name: 'photo_report',
        requirements: ['uuid' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}'],
        methods: ['GET'],
    )]
    public function form(string $uuid): Response
    {
        // docs/specs/photo-uploads.md §6c — no lookup: GET must not be an existence oracle.
        return $this->render('media/report.html.twig', $this->context($uuid, sent: false));
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

        // docs/specs/photo-uploads.md §6c — PoW only while the urgent breaker is open.
        if (MediaTakedownCategory::autoWithholds($category) && $this->breaker->isOpen()
            && !$this->proofOfWork->verify(
                (string) $request->request->get('pow_challenge', ''),
                (string) $request->request->get('pow_nonce', ''),
                new \DateTimeImmutable(),
            )
        ) {
            return $this->formWithError($uuid, 'media.report.error.challenge', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // docs/specs/security-architecture.md §7 — limiters before lookup so 429 is not an oracle.
        if (!$this->mediaReportLimiter->create('ip-'.$ip)->consume()->isAccepted()) {
            return $this->formWithError($uuid, 'media.report.error.rate_limited', Response::HTTP_TOO_MANY_REQUESTS);
        }
        if (MediaTakedownCategory::autoWithholds($category)
            && !$this->mediaReportUrgentLimiter->create('ip-'.$ip)->consume()->isAccepted()) {
            return $this->formWithError($uuid, 'media.report.error.rate_limited', Response::HTTP_TOO_MANY_REQUESTS);
        }

        $upload = $this->em->find(MediaUpload::class, Uuid::fromString($uuid));
        if (null !== $upload) {
            $this->takedowns->report($upload, $category, $reason, '' !== $contact ? $contact : null, $ip);
        }

        return $this->render('media/report.html.twig', $this->context($uuid, sent: true));
    }

    private function formWithError(string $uuid, string $error, int $status): Response
    {
        return $this->render('media/report.html.twig', $this->context($uuid, sent: false, error: $error), new Response('', $status));
    }
}
