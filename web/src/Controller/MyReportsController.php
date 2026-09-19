<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Pagination\Pager;
use App\Routing\LocalePrefix;
use App\Support\SupportMailer;
use App\Support\SupportRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "What happened to the bugs I reported?".
 *
 * A rider who takes the trouble to report something and then never hears
 * anything learns not to bother a second time. The outcome mail covers the
 * moment a report is finished; this page covers every moment before that, which
 * is most of them.
 *
 * **Their own rows and nothing else.** The query filters on the signed-in
 * user's id and is never given an id from the request. That is not a detail:
 * bug reports hold whatever a stranger typed, up to and including their own
 * address, and this is the one screen where a mistake would show one rider
 * another rider's report.
 *
 * **Anonymous reports are not here, and cannot be.** A report filed while
 * signed out carries no account, so there is nothing to match it against later
 * and signing in afterwards does not adopt it. The bug form says so before it is
 * sent, so nobody is surprised.
 *
 * @see docs/specs/contact-and-support.md §10
 *
 * @api
 */
#[Route(LocalePrefix::PATHS)]
#[IsGranted('ROLE_USER')]
final class MyReportsController extends AbstractController
{
    private const int PER_PAGE = 20;

    public function __construct(
        private readonly SupportRepository $repository,
        private readonly SupportMailer $mailer,
    ) {
    }

    #[Route('/account/reports', name: 'my_reports', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $userId = $user->getId();
        if (null === $userId) {
            throw $this->createAccessDeniedException();
        }

        $pager = Pager::of(
            $request->query->getInt('page', 1),
            $this->repository->countBugsByUser($userId),
            self::PER_PAGE,
        );

        $reports = $this->repository->bugsByUser($userId, $pager['perPage'], $pager['offset']);

        $references = [];
        foreach ($reports as $report) {
            $references[(int) $report->getId()] = $this->mailer->bugReference($report);
        }

        return $this->render('account/reports.html.twig', [
            // The account shell prints the rider's own name in its kicker, and
            // Twig has no global for the current User, so every shell page
            // passes it under this name (see MessagesController).
            'cc_user' => $user,
            'page_title' => 'support.mine.title',
            'page_description' => 'support.mine.title',
            'nav_active' => '',
            'reports' => $reports,
            'references' => $references,
            'pager' => $pager,
        ]);
    }
}
