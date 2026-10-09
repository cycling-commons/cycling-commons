<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Media\UrgentWithholdBreaker;
use App\Moderation\DeskRider;
use App\Pagination\Pager;
use App\Routing\LocalePrefix;
use App\Support\ContentReportService;
use App\Support\Entity\ContentReport;
use App\Support\ReportDecisionRefused;
use App\Support\ReportGround;
use App\Support\ReportResolver;
use App\Support\ReportStatus;
use App\Support\ReportTarget;
use App\Support\SupportRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * The reports desk: notice and action, DSA Articles 16 and 17.
 *
 * **It decides about the report, not about the content.** Following
 * `one-way-to-moderate`, nothing here edits or hides a route, a place or a
 * profile. The curator goes to the surface that already moderates that thing,
 * does the work there, and comes back to record what happened. A desk that
 * grew its own delete button would be a second moderation path with its own
 * history, its own permissions and its own bugs.
 *
 * **A photo is the exception, twice.** A decision on a photo report carries
 * through to the file ({@see ContentReportService::decide()}), because the
 * report raised the takedown only this desk decides. And a photo report can be
 * escalated from here, the same verb the takedowns and submission desks have,
 * because a report of illegal imagery has to be held where it is read.
 *
 * **Three people can be owed an answer, and they are not the same person.**
 * The reporter is owed the outcome whatever it is (Article 16(5)). The author
 * is owed a statement of reasons only when the report was upheld, because only
 * then was anything restricted (Article 17(1)). And nobody is owed anything for
 * a report that arrived about content that was already gone.
 *
 * **Legal grounds sort to the top.** {@see SupportRepository::reports()} orders
 * unlawfulness and personal-data claims before the rest, because those two are
 * the ones with a legal clock on them.
 *
 * @see docs/specs/content-reports.md §9
 *
 * @api
 */
#[Route(LocalePrefix::PATHS)]
#[IsGranted('ROLE_CURATOR')]
final class ModerateReportsController extends AbstractController
{
    private const string CSRF_TOKEN_ID = 'report-desk-decide';
    private const string ESCALATE_TOKEN_ID = 'report-desk-escalate';
    /** Refusals shown next to the status field, not in the page banner. */
    private const string STATUS_FLASH = 'report_status';
    /** Refusals shown inside the escalate panel. */
    private const string ESCALATE_FLASH = 'report_escalate';
    private const int PER_PAGE = 25;
    /** The status chip that shows every status. */
    private const string ALL = 'all';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SupportRepository $repository,
        private readonly ContentReportService $reports,
        private readonly ReportResolver $resolver,
        private readonly UrgentWithholdBreaker $breaker,
    ) {
    }

    #[Route('/moderate/reports', name: 'moderate_reports', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $statusParam = (string) $request->query->get('status', '');
        $status = ReportStatus::tryFrom($statusParam);
        $target = ReportTarget::tryFrom((string) $request->query->get('target', ''));

        // Unfiltered means "the ones nobody has answered yet", the same default
        // the bugs desk and the inbox use. `all` is the explicit "every status",
        // so that default can be left on purpose.
        $showing = self::ALL !== $statusParam && null === $status && null === $target ? ReportStatus::Open : $status;

        $pager = Pager::of(
            $request->query->getInt('page', 1),
            $this->repository->countReports($showing, $target),
            self::PER_PAGE,
        );

        $rows = [];
        foreach ($this->repository->reports($showing, $target, $pager['perPage'], $pager['offset']) as $report) {
            $rows[] = [
                'report' => $report,
                'resolved' => $this->resolver->resolve($report),
                'held' => $this->reports->isHeld($report),
            ];
        }

        return $this->render('moderate/reports.html.twig', [
            'page_title' => 'report.desk.title',
            'page_description' => 'report.desk.title',
            'nav_active' => '',
            'active' => 'moderate_reports',
            'rows' => $rows,
            'counts' => $this->repository->reportCountsByStatus($target),
            'count_all' => $this->repository->countReports(null, $target),
            'statuses' => ReportStatus::all(),
            'targets' => ReportTarget::cases(),
            'filter_status' => (null !== $showing ? $showing->value : self::ALL),
            'filter_target' => $target?->value,
            'pager' => $pager,
            // Urgent photo reports are decided here, so the spent auto-withhold
            // budget is announced here too (photo-uploads.md §6c).
            'urgent_breaker_open' => $this->breaker->isOpen(),
        ]);
    }

    #[Route('/moderate/reports/{id}', name: 'moderate_reports_detail', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function detail(string $id): Response
    {
        $report = $this->report($id);
        $resolved = $this->resolver->resolve($report);

        $siblings = [];
        foreach ($this->repository->siblingReports($report) as $other) {
            $siblings[] = $other;
        }

        $photo = $this->reports->photoOf($report);
        $held = null !== $photo && $photo->isEscalated();
        // Moot is left out while a takedown waits on the photo (canBeMoot()).
        $statuses = array_values(array_filter(
            ReportStatus::all(),
            fn (ReportStatus $st): bool => ReportStatus::Moot !== $st || $st === $report->getStatus() || $this->reports->canBeMoot($report),
        ));

        return $this->render('moderate/report_detail.html.twig', [
            'page_title' => 'report.desk.title',
            'page_description' => 'report.desk.title',
            'nav_active' => '',
            'active' => 'moderate_reports',
            'report' => $report,
            // The rules a curator may name to uphold a "Something else" report.
            'rules' => ReportGround::rulesFor($report->getTargetType()),
            'resolved' => $resolved,
            'siblings' => $siblings,
            'statuses' => $statuses,
            // Under legal hold: shown as such, with nothing left to decide.
            'held' => $held,
            'held_since' => $photo?->getEscalatedAt(),
            // Escalate is offered for a photo that still has files to hold.
            'can_escalate' => !$held && null !== $photo && null === $photo->getObjectsDeletedAt(),
            // Named the way every desk names people (DeskRider).
            'author_rider' => null !== $resolved['author'] ? DeskRider::ofUser($resolved['author']) : null,
            'decided_by' => null !== ($curator = $this->curator($report)) ? DeskRider::colleagueUser($curator) : null,
        ]);
    }

    /**
     * Record the decision, and send what it owes.
     *
     * The note is required for every decision, not only the ones that go
     * against somebody. It is the text that lands in the reporter's email and
     * in the author's statement of reasons, so "upheld" with an empty note
     * produces a legally required message that explains nothing.
     *
     * An upheld decision tells the author whenever the resolver finds one
     * (DSA Article 17 is owed, not offered), and
     * {@see ContentReportService::tellAuthor()} refuses to send twice.
     */
    #[Route('/moderate/reports/{id}/decide', name: 'moderate_reports_decide', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function decide(string $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->request->get('_token'))) {
            $this->addFlash('notice', 'flash.invalid_token');

            return $this->redirectToRoute('moderate_reports_detail', ['id' => $id]);
        }

        $report = $this->report($id);

        $status = ReportStatus::tryFrom((string) $request->request->get('status', ''));
        if (null === $status) {
            $this->addFlash('notice', 'report.desk.flash_bad_status');

            return $this->redirectToRoute('moderate_reports_detail', ['id' => $id]);
        }

        // Refused before anything else is looked at: a photo under legal hold,
        // Moot while a takedown waits, Upheld with nothing left to remove.
        $refusal = $this->reports->refusal($report, $status);
        if (null !== $refusal) {
            $this->addFlash(self::STATUS_FLASH, $refusal);

            return $this->redirectToRoute('moderate_reports_detail', ['id' => $id]);
        }

        // Open and being-looked-at are the two waiting states: no note, no
        // mail, the clock runs on. Open again is how a curator hands it back.
        if (!$status->isDecided()) {
            $this->reports->takeUp($report, $status);
            $this->addFlash('notice', 'report.desk.flash_taken_up');

            return $this->redirectToRoute('moderate_reports_detail', ['id' => $id]);
        }

        $note = trim((string) $request->request->get('note', ''));
        if ('' === $note) {
            $this->addFlash('notice', 'report.desk.flash_needs_note');

            return $this->redirectToRoute('moderate_reports_detail', ['id' => $id]);
        }

        $curator = $this->getUser();
        if (!$curator instanceof User) {
            throw $this->createAccessDeniedException();
        }

        // Persists and mails the reporter, if they left an address.
        try {
            $this->reports->decide($report, $status, $note, $curator, ReportGround::tryFrom((string) $request->request->get('rule', '')));
        } catch (ReportDecisionRefused $e) {
            $this->addFlash(self::STATUS_FLASH, $e->getMessage());

            return $this->redirectToRoute('moderate_reports_detail', ['id' => $id]);
        }

        // Upheld owes the author a statement of reasons, every time there is
        // one to tell (content-reports.md §7); tellAuthor() sends it once.
        $resolved = $this->resolver->resolve($report);
        $author = $resolved['author'];
        if ($author instanceof User) {
            $this->reports->tellAuthor($report, $author, $resolved['label']);
        }
        // An author who answered the claim was promised the decision.
        if ($report->hasCounterNotice() && $author instanceof User) {
            $this->reports->tellAuthorTheOutcome($report, $author);
        }

        $this->addFlash('notice', 'report.desk.flash_saved');

        return $this->redirectToRoute('moderate_reports_detail', ['id' => $id]);
    }

    /**
     * Escalate the photo a report is about as suspected illegal content.
     *
     * The same verb, hold and alert as on the takedowns and submission desks
     * (photo-uploads.md §6d), placed where a third-party report is decided so
     * a report of illegal imagery can be held there. Photos only: no other
     * target has a legal hold to put it under (content-reports.md §11). Not
     * region-scoped, like everything else on this desk (§9).
     *
     * Like the other desks it asks for a sentence in the curator's own words
     * and a tick, and the tick is checked here too: a request without it
     * holds nothing.
     */
    #[Route('/moderate/reports/{id}/escalate', name: 'moderate_reports_escalate', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function escalate(string $id, Request $request): Response
    {
        $report = $this->report($id);
        if (!$report->getTargetType()->canAutoWithhold()) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid(self::ESCALATE_TOKEN_ID, (string) $request->request->get('_token'))) {
            $this->addFlash('notice', 'flash.invalid_token');

            return $this->redirectToRoute('moderate_reports_detail', ['id' => $id]);
        }

        if (!$request->request->getBoolean('confirm')) {
            $this->addFlash(self::ESCALATE_FLASH, 'report.desk.escalate_needs_tick');

            return $this->redirectToRoute('moderate_reports_detail', ['id' => $id]);
        }

        $curator = $this->getUser();
        if (!$curator instanceof User) {
            throw $this->createAccessDeniedException();
        }

        try {
            $this->reports->escalate($report, $curator, (string) $request->request->get('reason', ''));
            $this->addFlash('notice', 'moderate.escalate.done');
        } catch (ReportDecisionRefused|\InvalidArgumentException $e) {
            $this->addFlash(self::ESCALATE_FLASH, $e->getMessage());
        }

        return $this->redirectToRoute('moderate_reports_detail', ['id' => $id]);
    }

    private function report(string $id): ContentReport
    {
        if (!Uuid::isValid($id)) {
            throw $this->createNotFoundException();
        }

        $report = $this->em->find(ContentReport::class, Uuid::fromString($id));
        if (!$report instanceof ContentReport) {
            throw $this->createNotFoundException();
        }

        return $report;
    }

    private function curator(ContentReport $report): ?User
    {
        $id = $report->getDecidedById();
        if (null === $id) {
            return null;
        }

        $user = $this->em->find(User::class, $id);

        return $user instanceof User ? $user : null;
    }
}
