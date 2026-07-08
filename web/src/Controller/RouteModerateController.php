<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\RouteSuggestionStatus;
use App\Entity\User;
use App\Form\RouteDecisionType;
use App\Moderation\RegionFullException;
use App\Moderation\RouteModerationService;
use App\Moderation\RouteQueue;
use App\Routing\LocalePrefix;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Curator "Routes" moderation desk (spec §6). index() lists pending route
 * proposals + suggestions; decide() applies approve/reject/retire via
 * RouteModerationService (the only write-path). detail()/edit() land in a
 * later task.
 *
 * @api Instantiated by Symfony's router.
 */
#[Route(LocalePrefix::PATHS)]
#[IsGranted('ROLE_CURATOR')]
final class RouteModerateController extends AbstractController
{
    public function __construct(
        private readonly RouteQueue $queue,
        private readonly RouteModerationService $moderation,
    ) {
    }

    #[Route('/moderate/routes', name: 'moderate_routes')]
    public function index(Request $request): Response
    {
        $region = $request->query->get('region');
        $regionId = null !== $region && ctype_digit((string) $region) ? (int) $region : null;

        $rows = $this->queue->pending($regionId);
        $forms = [];
        foreach ($rows as $row) {
            $forms[$row['id']] = $this->createForm(RouteDecisionType::class, null, [
                'action' => $this->generateUrl('moderate_routes_decide', null !== $regionId ? ['region' => $regionId] : []),
            ])->createView();
        }

        return $this->render('moderate_routes/index.html.twig', [
            'nav_active' => 'moderate_routes',
            'routes' => $rows,
            'forms' => $forms,
            'suggestions' => $this->queue->pendingSuggestions($regionId),
            'total' => $this->queue->total(),
            'regions' => $this->queue->regions(),
            'filter_region' => $regionId,
            'page_title' => 'moderate_routes.meta_title',
            'page_description' => 'moderate_routes.meta_description',
        ]);
    }

    #[Route('/moderate/routes/decide', name: 'moderate_routes_decide', methods: ['POST'])]
    public function decide(Request $request): Response
    {
        $form = $this->createForm(RouteDecisionType::class);
        $form->handleRequest($request);
        $back = $this->redirectToRoute('moderate_routes', $request->query->has('region') ? ['region' => $request->query->get('region')] : []);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('danger', 'moderate_routes.error.invalid_decision');

            return $back;
        }

        /** @var array{route_id:string, decision:string, note:?string} $data */
        $data = $form->getData();
        /** @var User $curator */
        $curator = $this->getUser();
        $id = (int) $data['route_id'];
        $note = $data['note'] ?? null;

        try {
            match ($data['decision']) {
                'approve' => $this->moderation->approve($id, $curator),
                'reject' => $this->moderation->reject($id, $curator, $note),
                'retire' => $this->moderation->retire($id, $curator, (string) $note),
                default => throw new \InvalidArgumentException('bad decision'),
            };
            $this->addFlash('success', 'moderate_routes.flash.decided');
        } catch (RegionFullException) {
            $this->addFlash('danger', 'moderate_routes.error.region_full');
        } catch (\InvalidArgumentException|\LogicException) {
            $this->addFlash('danger', 'moderate_routes.error.undecidable');
        }

        return $back;
    }

    #[Route('/moderate/routes/suggestion', name: 'moderate_routes_suggestion', methods: ['POST'])]
    public function resolveSuggestion(Request $request): Response
    {
        $this->validateCsrf($request, 'route-suggestion');
        /** @var User $curator */
        $curator = $this->getUser();
        $status = RouteSuggestionStatus::from((string) $request->request->get('status'));
        try {
            $this->moderation->resolveSuggestion((int) $request->request->get('suggestion_id'), $status, $curator);
            $this->addFlash('success', 'moderate_routes.flash.suggestion_resolved');
        } catch (\InvalidArgumentException|\LogicException) {
            $this->addFlash('danger', 'moderate_routes.error.undecidable');
        }

        return $this->redirectToRoute('moderate_routes');
    }

    private function validateCsrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
