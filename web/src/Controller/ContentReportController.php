<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Security\FormGuard;
use App\Support\ContentReportService;
use App\Support\Entity\ContentReport;
use App\Support\ReportGround;
use App\Support\ReportResolver;
use App\Support\ReportTarget;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * "Report this" for everything that is not a photo.
 *
 * DSA Article 16: any person, **no account**, any content. So this sits outside
 * the firewall, takes an optional reply address rather than requiring one, and
 * never asks who you are.
 *
 * Three things it borrows from the photo report, which has been running since
 * August and got them right:
 *
 * - **The GET never looks anything up.** A form that 404s for an id that does
 *   not exist is an existence oracle; this one renders for any well-formed id
 *   and the lookup happens after the report is filed.
 * - **Rate limiting comes before the lookup**, so a 429 cannot be used as one
 *   either.
 * - **The answer is the same whether or not the target existed.** "Thank you,
 *   a curator will look" is true in both cases: a report about something that
 *   is already gone is closed as moot, which is a real outcome.
 *
 * @see docs/specs/content-reports.md §5
 *
 * @api
 */
final class ContentReportController extends AbstractController
{
    public function __construct(
        private readonly ContentReportService $reports,
        private readonly FormGuard $guard,
        private readonly RateLimiterFactory $contentReportLimiter,
        // The urgent ground keeps the tighter budget the photo form gave it:
        // one a day per address. Auto-withhold takes a picture down before a
        // person has looked, so the ground that triggers it must be the hardest
        // to fire in volume. Without this, folding the photo form into the
        // shared route would have traded 1/day for 15/day.
        private readonly RateLimiterFactory $mediaReportUrgentLimiter,
        private readonly EntityManagerInterface $em,
        private readonly ReportResolver $resolver,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * The uploader answers a rights claim, once.
     *
     * Signed in, unlike everything else on this controller, and deliberately:
     * whoever posted the content has an account by definition, the Article 17
     * statement went to that account's address, and a login is a better proof
     * of who they are than any token in a mailed URL.
     *
     * Only for a copyright report, only when it was upheld, only for the person
     * who wrote the thing, and only while no answer has been given yet.
     */
    #[Route('/report/{id}/answer', name: 'content_report_answer', methods: ['GET', 'POST'], requirements: ['id' => '[0-9a-f-]{36}'])]
    #[IsGranted('ROLE_USER')]
    public function answer(string $id, Request $request): Response
    {
        $report = $this->em->find(ContentReport::class, Uuid::fromString($id));
        $user = $this->getUser();
        if (!$report instanceof ContentReport || !$user instanceof User) {
            throw $this->createNotFoundException();
        }

        $resolved = $this->resolver->resolve($report);
        $author = $resolved['author'];
        if (!$report->getGround()->needsOwnershipProof()
            || !$report->getStatus()->owesStatementOfReasons()
            || !$author instanceof User
            || $author->getId() !== $user->getId()
        ) {
            throw $this->createNotFoundException();
        }

        $done = $report->hasCounterNotice();
        if ($request->isMethod('POST') && !$done) {
            if (!$this->isCsrfTokenValid('content_report_answer', (string) $request->request->get('_token'))) {
                return $this->render('support/report_answer.html.twig', [
                    'page_title' => 'meta.report_title', 'nav_active' => '',
                    'report' => $report, 'done' => false, 'error' => 'flash.invalid_token',
                ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            $text = trim((string) $request->request->get('answer', ''));
            if ('' === $text) {
                return $this->render('support/report_answer.html.twig', [
                    'page_title' => 'meta.report_title', 'nav_active' => '',
                    'report' => $report, 'done' => false, 'error' => 'report.error.answer_required',
                ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            $report->recordCounterNotice(mb_substr($text, 0, ContentReportService::REASON_MAX), $this->clock->now());
            $this->em->flush();
            $done = true;
        }

        return $this->render('support/report_answer.html.twig', [
            'page_title' => 'meta.report_title', 'nav_active' => '',
            'report' => $report, 'done' => $done, 'error' => null,
        ]);
    }

    #[Route('/report/{type}/{id}', name: 'content_report', methods: ['GET'], requirements: ['id' => '[A-Za-z0-9-]{1,64}'])]
    public function form(string $type, string $id, Request $request): Response
    {
        return $this->render('support/report.html.twig', $this->context($type, $id, sent: false, request: $request));
    }

    #[Route('/report/{type}/{id}', name: 'content_report_submit', methods: ['POST'], requirements: ['id' => '[A-Za-z0-9-]{1,64}'])]
    public function submit(string $type, string $id, Request $request): Response
    {
        $target = $this->target($type, $id);

        // "Which thing?" A picture on the page is reported as itself, not as
        // the entry it hangs on. Validated against that entry's own gallery, so
        // one item's form cannot be used to file against somebody else's photo.
        $about = trim((string) $request->request->get('about', ''));
        if ('' !== $about && 'entry' !== $about) {
            $onPage = array_column($this->resolver->photosOn($target, $id), 'id');
            if (!\in_array($about, $onPage, true)) {
                return $this->error($type, $id, 'report.error.about', Response::HTTP_UNPROCESSABLE_ENTITY, $request);
            }
            $type = 'photo';
            $id = $about;
            $target = ReportTarget::Photo;
        }

        if (!$this->isCsrfTokenValid('content_report', (string) $request->request->get('_token'))) {
            return $this->error($type, $id, 'flash.invalid_token', Response::HTTP_UNPROCESSABLE_ENTITY, $request);
        }

        // Honeypots and the signed stamp, the same guard the contact form uses.
        // It returns the catalogue key of what went wrong, or null for fine.
        $rejected = $this->guard->reject($request, new \DateTimeImmutable());
        if (null !== $rejected) {
            return $this->error($type, $id, $rejected, Response::HTTP_UNPROCESSABLE_ENTITY, $request);
        }

        // A ground that does not apply to THIS target is not merely absent from
        // the list: posting it straight at the endpoint has to fail too, or the
        // enum's meaning would depend on the markup.
        $ground = ReportGround::tryFrom((string) $request->request->get('ground', ''));
        if (null === $ground || !\in_array($ground, ReportGround::forTarget($target), true)) {
            return $this->error($type, $id, 'report.error.ground', Response::HTTP_UNPROCESSABLE_ENTITY, $request);
        }

        $reason = trim((string) $request->request->get('reason', ''));
        if ('' === $reason) {
            return $this->error($type, $id, 'report.error.reason', Response::HTTP_UNPROCESSABLE_ENTITY, $request);
        }
        if (mb_strlen($reason) > ContentReportService::REASON_MAX) {
            return $this->error($type, $id, 'report.error.reason_long', Response::HTTP_UNPROCESSABLE_ENTITY, $request);
        }

        $contact = trim((string) $request->request->get('contact', ''));
        // An address is required for every ground but the child one, where the
        // DSA forbids demanding it. The check is on the GROUND and not on the
        // markup, so a form rendered before the rule changed cannot slip past.
        if ('' === $contact && $ground->requiresContact()) {
            return $this->error($type, $id, 'report.error.contact_required', Response::HTTP_UNPROCESSABLE_ENTITY, $request);
        }
        if ('' !== $contact && false === filter_var($contact, \FILTER_VALIDATE_EMAIL)) {
            return $this->error($type, $id, 'report.error.contact', Response::HTTP_UNPROCESSABLE_ENTITY, $request);
        }

        // A rights claim carries two more things, and only a rights claim is
        // asked for them. Where the original is, so a curator can compare, and
        // the name it is made under, which is the one field on this form that
        // the other side gets to see: a person cannot answer a claim without
        // knowing who makes it.
        $work = trim((string) $request->request->get('work_original', ''));
        $claimant = trim((string) $request->request->get('claimant_name', ''));
        if ($ground->needsOwnershipProof()) {
            if ('' === $work) {
                return $this->error($type, $id, 'report.error.work_required', Response::HTTP_UNPROCESSABLE_ENTITY, $request);
            }
            if ('' === $claimant) {
                return $this->error($type, $id, 'report.error.claimant_required', Response::HTTP_UNPROCESSABLE_ENTITY, $request);
            }
            if ('1' !== (string) $request->request->get('rights_statement', '')) {
                return $this->error($type, $id, 'report.error.statement_required', Response::HTTP_UNPROCESSABLE_ENTITY, $request);
            }
        }

        $ip = $request->getClientIp() ?? 'unknown';
        if (!$this->contentReportLimiter->create('ip-'.$ip)->consume()->isAccepted()) {
            return $this->error($type, $id, 'report.error.rate_limited', Response::HTTP_TOO_MANY_REQUESTS, $request);
        }
        if ($ground->autoWithholds() && !$this->mediaReportUrgentLimiter->create('ip-'.$ip)->consume()->isAccepted()) {
            return $this->error($type, $id, 'report.error.rate_limited', Response::HTTP_TOO_MANY_REQUESTS, $request);
        }

        $report = $this->reports->file($target, $id, $ground, $reason, '' !== $contact ? $contact : null, null, $ip);
        $report->setFromPath($this->cleanPath((string) $request->request->get('from', '')));
        if ($ground->needsOwnershipProof()) {
            $report->setRightsClaim(
                mb_substr($work, 0, ContentReportService::REASON_MAX),
                mb_substr($claimant, 0, 180),
            );
        }
        $this->em->flush();

        return $this->render('support/report.html.twig', $this->context($type, $id, sent: true, request: $request));
    }

    /**
     * Keep a path of ours, and nothing else.
     *
     * The link carries it, so it is not something a reporter typed, but it
     * arrives in a request and is therefore a stranger's string. Path only:
     * a query string on this site can carry somebody's search terms, and an
     * off-site URL is somebody else's business.
     */
    private function cleanPath(string $candidate): ?string
    {
        if ('' === $candidate) {
            return null;
        }
        if (!str_starts_with($candidate, '/') || str_starts_with($candidate, '//')) {
            return null;
        }

        return mb_substr(explode('?', $candidate)[0], 0, 500);
    }

    /** A type we do not know, or an id shaped wrong, is a 404 and not a hint. */
    private function target(string $type, string $id): ReportTarget
    {
        $target = ReportTarget::tryFromPath($type);
        if (null === $target || !$target->acceptsId($id)) {
            throw $this->createNotFoundException();
        }

        return $target;
    }

    /**
     * @return array<string, mixed>
     */
    private function context(string $type, string $id, bool $sent, ?string $error = null, ?Request $request = null): array
    {
        $target = $this->target($type, $id);

        return [
            'page_title' => 'meta.report_title',
            'page_description' => 'meta.report_description',
            'nav_active' => '',
            'target' => $target,
            'target_id' => $id,
            'grounds' => ReportGround::forTarget($target),
            // The pictures on the same page, so "Report this page" stops
            // meaning only the entry. See the design spec §2: one open drawer
            // holds the entry AND its photographs.
            'photos' => $this->resolver->photosOn($target, $id),
            'photo_grounds' => ReportGround::forTarget(ReportTarget::Photo),
            'sent' => $sent,
            'error' => $error,
            'from_path' => $this->cleanPath(
                (string) ($request?->query->get('from') ?? '')
            ),
            'guard' => [
                'stamp_field' => FormGuard::STAMP,
                'stamp' => $this->guard->stamp(new \DateTimeImmutable()),
                'honeypot_a' => FormGuard::HONEYPOT_A,
                'honeypot_b' => FormGuard::HONEYPOT_B,
            ],
        ];
    }

    private function error(string $type, string $id, string $key, int $status, ?Request $request = null): Response
    {
        return $this->render(
            'support/report.html.twig',
            $this->context($type, $id, sent: false, error: $key, request: $request),
            new Response('', $status),
        );
    }
}
