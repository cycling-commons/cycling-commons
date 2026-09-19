<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\CatalogFindingRepository;
use App\Entity\User;
use App\Media\MediaTakedownService;
use App\Messaging\CuratorRoom;
use App\Moderation\ModerationScopeProvider;
use App\Moderation\RouteQueue;
use App\Moderation\SubmissionQueue;
use App\Routing\LocalePrefix;
use App\Support\SupportRepository;
use App\Translation\DecisionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The curator dashboard: every desk's open count on one page, the two legal
 * clocks first, then the room and this curator's own last decisions.
 *
 * Read-only. It decides nothing and marks nothing seen: the room badge still
 * counts until the curator opens the room itself.
 *
 * @see docs/specs/moderation-and-contribution.md §5.0
 *
 * @api
 */
#[Route(LocalePrefix::PATHS)]
#[IsGranted('ROLE_CURATOR')]
final class ModerateDashboardController extends AbstractController
{
    /** Room posts shown on the dashboard. */
    public const int ROOM_POSTS = 3;

    /** This curator's own decisions shown on the dashboard. */
    public const int MY_DECISIONS = 5;

    public function __construct(
        private readonly ModerationScopeProvider $scopeProvider,
        private readonly SubmissionQueue $submissions,
        private readonly RouteQueue $routes,
        private readonly CatalogFindingRepository $findings,
        private readonly MediaTakedownService $takedowns,
        private readonly SupportRepository $support,
        private readonly DecisionService $translations,
        private readonly CuratorRoom $room,
    ) {
    }

    #[Route('/moderate', name: 'moderate_dashboard', methods: ['GET'])]
    public function index(Request $request): Response
    {
        // The dashboard takes no query. A query here is a submissions-queue
        // link with filters (in a sent email or a bookmark): it goes on to
        // the queue.
        if ($request->query->count() > 0) {
            return $this->redirectToRoute('moderate_submissions', $request->query->all(), Response::HTTP_MOVED_PERMANENTLY);
        }

        /** @var User $curator */
        $curator = $this->getUser();
        $curatorId = (int) $curator->getId();
        $scope = $this->scopeProvider->scopeFor($curator);

        $submissionCount = $this->submissions->total($scope);
        $routeCount = $this->routes->total($scope) + $this->routes->pendingSuggestionCount($scope);
        $takedownCount = $this->takedowns->pendingCount();

        // Scoped desks count what is in this curator's area; the rest count
        // everything, like their tabs do.
        $desks = [
            ['key' => 'submissions', 'route' => 'moderate_submissions', 'label' => 'nav.moderate', 'count' => $submissionCount, 'scoped' => true],
            ['key' => 'routes', 'route' => 'moderate_routes', 'label' => 'nav.routes', 'count' => $routeCount, 'scoped' => true],
            ['key' => 'data', 'route' => 'moderate_data', 'label' => 'nav.data', 'count' => $this->findings->openCount($scope), 'scoped' => true],
            ['key' => 'bugs', 'route' => 'moderate_bugs', 'label' => 'support.bugs.title', 'count' => $this->support->openBugCount(), 'scoped' => false],
            ['key' => 'translations', 'route' => 'moderate_translations', 'label' => 'nav.translations', 'count' => $this->translations->pendingCount(), 'scoped' => false],
            ['key' => 'room', 'route' => 'moderate_room', 'label' => 'nav.room', 'count' => $this->room->unreadCount($curatorId), 'scoped' => false],
        ];

        // Takedowns and reports run on a legal clock, so they stand apart
        // from the editorial desks (photo-uploads.md §6b, content-reports.md §9).
        $clocks = [
            ['key' => 'takedowns', 'route' => 'moderate_takedowns', 'label' => 'nav.takedowns', 'count' => $takedownCount],
            ['key' => 'reports', 'route' => 'moderate_reports', 'label' => 'report.desk.title', 'count' => $this->support->openReportCount()],
        ];

        $board = $this->room->board($curatorId, 'all', $this->isGranted('ROLE_ADMIN'));

        return $this->render('moderate/dashboard.html.twig', [
            'page_title' => 'meta.moderate_dashboard_title',
            'page_description' => 'meta.moderate_dashboard_description',
            'nav_active' => 'moderate_dashboard',
            'clocks' => $clocks,
            'clocks_open' => array_sum(array_column($clocks, 'count')),
            'desks' => $desks,
            'room_posts' => \array_slice($board['posts'], 0, self::ROOM_POSTS),
            'my_decisions' => $this->submissions->history($scope, $curatorId, perPage: self::MY_DECISIONS),
            'mod_submission_count' => $submissionCount,
            'mod_route_count' => $routeCount,
            'mod_takedown_count' => $takedownCount,
        ]);
    }
}
