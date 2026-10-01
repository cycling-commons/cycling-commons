<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Moderation\ModerationScopeProvider;
use App\Moderation\ModerationService;
use App\Moderation\OutOfScopeException;
use App\Moderation\RouteModerationService;
use App\Moderation\TrashBin;
use App\Pagination\Pager;
use App\Pagination\PageSize;
use App\Routing\LocalePrefix;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The curators' Trash: what is in it, in the reader's areas, and Restore.
 *
 * One list for the three things Trash takes (a submission, a route
 * correction, a route proposal), each shown with what it said and its
 * thread, and the day the purge deletes it for good.
 *
 * @see docs/specs/moderation-and-contribution.md §6
 *
 * @api
 */
#[Route(LocalePrefix::PATHS)]
#[IsGranted('ROLE_CURATOR')]
final class ModerateTrashController extends AbstractController
{
    public function __construct(
        private readonly TrashBin $bin,
        private readonly ModerationService $moderation,
        private readonly RouteModerationService $routes,
        private readonly ModerationScopeProvider $scopeProvider,
        private readonly PageSize $pageSize,
    ) {
    }

    #[Route('/moderate/trash', name: 'moderate_trash_bin', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->bin->sweepOpportunistically();

        /** @var User $user */
        $user = $this->getUser();
        $scope = $this->scopeProvider->scopeFor($user);
        $page = max(1, $request->query->getInt('page', 1));
        $perPage = $this->pageSize->resolve(TrashBin::PER_PAGE);

        return $this->render('moderate/trash.html.twig', [
            'page_title' => 'meta.moderate_trash_title',
            'page_description' => 'meta.moderate_trash_description',
            'nav_active' => 'moderate',
            'entries' => $this->bin->page($scope, $page, $perPage),
            'pager' => Pager::of($page, $this->bin->count($scope), $perPage),
            'trash_days' => TrashBin::TRASH_DAYS,
        ]);
    }

    /**
     * Back to exactly what it was before Trash. The rider is told nothing,
     * as they were told nothing when it went.
     */
    #[Route('/moderate/trash/restore', name: 'moderate_trash_restore', methods: ['POST'])]
    public function restore(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('moderate-restore', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $kind = (string) $request->request->get('kind');
        $id = (int) $request->request->get('id');
        /** @var User $curator */
        $curator = $this->getUser();

        try {
            match ($kind) {
                TrashBin::KIND_SUBMISSION => $this->moderation->restoreSubmission($id, $curator),
                TrashBin::KIND_CORRECTION => $this->routes->restoreSuggestion($id, $curator),
                TrashBin::KIND_PROPOSAL => $this->routes->restoreProposal($id, $curator),
                default => throw new \InvalidArgumentException('Unknown trash kind.'),
            };
            $this->addFlash('success', 'moderate.trash.restored');
        } catch (OutOfScopeException) {
            throw $this->createAccessDeniedException('Out of moderation scope.');
        } catch (\InvalidArgumentException) {
            $this->addFlash('danger', 'moderate.trash.restore_error');
        }

        return $this->redirectToRoute('moderate_trash_bin', array_filter([
            'page' => $request->query->getInt('page') ?: null,
        ]));
    }
}
