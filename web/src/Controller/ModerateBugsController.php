<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Pagination\Pager;
use App\Routing\LocalePrefix;
use App\Support\BugArea;
use App\Support\BugSeverity;
use App\Support\BugStatus;
use App\Support\Entity\BugReport;
use App\Support\Entity\BugScreenshot;
use App\Support\SupportIntake;
use App\Support\SupportMailer;
use App\Support\SupportRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The bugs desk: its own place, not a queue inside another one.
 *
 * **Why separate from the inbox.** A contact message is a conversation with one
 * person and it is finished when they have an answer. A bug is a fact about the
 * software that outlives the person who reported it, gets a lifecycle, can be
 * published to the known-issues list, and is what somebody works from on a
 * Tuesday morning. Mixing the two gives you a list where "how do I add a water
 * tap" sits between two crashes.
 *
 * **Publishing is a decision, never a default.** {@see BugReport::isPublic()}
 * is off until a curator turns it on. The known-issues list is only worth
 * reading because somebody has checked every line, and a report written in
 * anger or naming a person must never become a public page by accident.
 *
 * **Screenshots never get a public URL.** They are served from
 * {@see screenshot()} to curators only, as an attachment, out of the database.
 * See {@see BugScreenshot} for why they are not in the media pipeline.
 *
 * @see docs/specs/contact-and-support.md §9
 *
 * @api
 */
#[Route(LocalePrefix::PATHS)]
#[IsGranted('ROLE_CURATOR')]
final class ModerateBugsController extends AbstractController
{
    private const string CSRF_TOKEN_ID = 'bug-desk-decide';
    private const int PER_PAGE = 25;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SupportRepository $repository,
        private readonly SupportIntake $intake,
        private readonly SupportMailer $mailer,
    ) {
    }

    #[Route('/moderate/bugs', name: 'moderate_bugs', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $status = BugStatus::tryFrom((string) $request->query->get('status', ''));
        $area = BugArea::tryFrom((string) $request->query->get('area', ''));

        // Same default as the inbox: unfiltered means "the new ones", because a
        // desk that opens on the full archive is a desk nobody opens.
        $showing = null === $status && null === $area ? BugStatus::New : $status;

        $pager = Pager::of(
            $request->query->getInt('page', 1),
            $this->repository->countBugs($showing, $area),
            self::PER_PAGE,
        );

        return $this->render('moderate/bugs.html.twig', [
            'page_title' => 'support.bugs.title',
            'page_description' => 'support.bugs.title',
            'nav_active' => '',
            'active' => 'moderate_bugs',
            'reports' => $this->repository->bugs($showing, $area, $pager['perPage'], $pager['offset']),
            'counts' => $this->repository->bugCountsByStatus(),
            'statuses' => BugStatus::all(),
            'areas' => BugArea::all(),
            'filter_status' => $showing?->value,
            'filter_area' => $area?->value,
            'pager' => $pager,
        ]);
    }

    #[Route('/moderate/bugs/{id}', name: 'moderate_bugs_detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function detail(int $id): Response
    {
        $report = $this->em->find(BugReport::class, $id);
        if (!$report instanceof BugReport) {
            throw $this->createNotFoundException();
        }

        return $this->render('moderate/bug_detail.html.twig', [
            'page_title' => 'support.bugs.title',
            'page_description' => 'support.bugs.title',
            'nav_active' => '',
            'active' => 'moderate_bugs',
            'report' => $report,
            'reference' => $this->mailer->bugReference($report),
            'statuses' => BugStatus::all(),
            'severities' => BugSeverity::all(),
            'areas' => BugArea::all(),
        ]);
    }

    /**
     * Set the status, severity, area, the public flag and the outcome note.
     *
     * One form and one round trip on purpose: a curator triaging twenty reports
     * should not have to save four times per report.
     *
     * Reaching Resolved or Declined mails the reporter, once, with the note. So
     * the note is required for those two: "we are not fixing this" with no
     * reason is the message that makes somebody never report anything again.
     */
    #[Route('/moderate/bugs/{id}/decide', name: 'moderate_bugs_decide', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function decide(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->request->get('_token'))) {
            $this->addFlash('notice', 'flash.invalid_token');

            return $this->redirectToRoute('moderate_bugs_detail', ['id' => $id]);
        }

        $report = $this->em->find(BugReport::class, $id);
        if (!$report instanceof BugReport) {
            throw $this->createNotFoundException();
        }

        $status = BugStatus::tryFrom((string) $request->request->get('status', ''));
        if (null === $status) {
            $this->addFlash('notice', 'support.bugs.flash_bad_status');

            return $this->redirectToRoute('moderate_bugs_detail', ['id' => $id]);
        }

        $note = trim((string) $request->request->get('outcome_note', ''));
        if ($status->notifiesReporter() && '' === $note) {
            $this->addFlash('notice', 'support.bugs.flash_outcome_needs_note');

            return $this->redirectToRoute('moderate_bugs_detail', ['id' => $id]);
        }

        $publicTitle = trim((string) $request->request->get('public_title', ''));
        $wantsPublic = $request->request->getBoolean('is_public');

        $report->setSeverity(BugSeverity::fromInput((string) $request->request->get('severity', $report->getSeverity()->value)));
        $report->setArea(BugArea::fromInput((string) $request->request->get('area', $report->getArea()->value)));
        $report->setPublicTitle('' !== $publicTitle ? $publicTitle : null);
        $report->setPublic($wantsPublic);
        if ('' !== $note) {
            $report->setOutcomeNote($note);
        }
        $report->setStatus($status);

        $curator = $this->getUser();
        if ($curator instanceof User) {
            $report->setHandledByUserId($curator->getId());
        }

        // Flushes, and mails the reporter exactly once if this status owes them
        // an outcome (SupportIntake::announceBugOutcome).
        $this->intake->announceBugOutcome($report);

        $this->addFlash('notice', 'support.bugs.flash_saved');

        return $this->redirectToRoute('moderate_bugs_detail', ['id' => $id]);
    }

    /**
     * Stream one screenshot to a curator.
     *
     * `attachment`, `nosniff` and an explicit `Content-Type` together: the bytes
     * are a PNG this server re-encoded ({@see \App\Support\ScreenshotStore}), so
     * the file is safe, but it arrived from a stranger and there is no reason to
     * let a browser render it inline on our own origin.
     *
     * `private, no-store` because it is somebody's screen, which may hold their
     * own email address, their own map, or an open tab they did not think about.
     */
    #[Route(
        '/moderate/bugs/{id}/shot/{shotId}',
        name: 'moderate_bugs_screenshot',
        requirements: ['id' => '\d+', 'shotId' => '\d+'],
        methods: ['GET'],
    )]
    public function screenshot(int $id, int $shotId): Response
    {
        $shot = $this->em->find(BugScreenshot::class, $shotId);
        if (!$shot instanceof BugScreenshot || $shot->getReport()?->getId() !== $id) {
            throw $this->createNotFoundException();
        }

        $response = new Response($shot->getBytes());
        $response->headers->set('Content-Type', $shot->getMimeType());
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Disposition', \sprintf('attachment; filename="bug-%d-%d.png"', $id, $shot->getPosition() + 1));
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
