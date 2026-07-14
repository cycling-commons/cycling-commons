<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\ItemState;
use App\Catalog\RouteSuggestionStatus;
use App\Catalog\SurfaceVocabulary;
use App\Entity\User;
use App\Form\RouteDecisionType;
use App\Moderation\ModerationScopeProvider;
use App\Moderation\OutOfScopeException;
use App\Moderation\RegionFullException;
use App\Moderation\RetentionService;
use App\Moderation\RouteModerationService;
use App\Moderation\RouteQueue;
use App\Moderation\TrashBlockedException;
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
        private readonly RetentionService $retention,
        private readonly ModerationScopeProvider $scopeProvider,
    ) {
    }

    #[Route('/moderate/routes', name: 'moderate_routes')]
    public function index(Request $request): Response
    {
        // Fire-and-forget housekeeping (throttled internally, never throws) —
        // must never delay or break the desk render.
        $this->retention->sweepOpportunistically();

        /** @var User $user */
        $user = $this->getUser();
        $scope = $this->scopeProvider->scopeFor($user);

        $region = $request->query->get('region');
        $regionId = null !== $region && ctype_digit((string) $region) ? (int) $region : null;

        $rows = $this->queue->pending($scope, $regionId);
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
            'suggestions' => $this->queue->pendingSuggestions($scope, $regionId),
            'total' => $this->queue->total($scope),
            'regions' => $this->queue->regions($scope),
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
        } catch (OutOfScopeException) {
            throw $this->createAccessDeniedException('Out of moderation scope.');
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
        $status = RouteSuggestionStatus::tryFrom((string) $request->request->get('status'));
        if (null === $status) {
            $this->addFlash('danger', 'moderate_routes.error.undecidable');

            return $this->redirectToRoute('moderate_routes');
        }
        try {
            $this->moderation->resolveSuggestion((int) $request->request->get('suggestion_id'), $status, $curator);
            $this->addFlash('success', 'moderate_routes.flash.suggestion_resolved');
        } catch (OutOfScopeException) {
            throw $this->createAccessDeniedException('Out of moderation scope.');
        } catch (\InvalidArgumentException|\LogicException) {
            $this->addFlash('danger', 'moderate_routes.error.undecidable');
        }

        return $this->redirectToRoute('moderate_routes');
    }

    #[Route('/moderate/routes/{id}', name: 'moderate_routes_detail', requirements: ['id' => '\d+'])]
    public function detail(int $id, \Doctrine\DBAL\Connection $db): Response
    {
        $row = $db->fetchAssociative(
            'SELECT id, name, ST_AsGeoJSON(geom) AS geom, distance_m, ascent_m, region_id, attributes, state
             FROM recommended_route WHERE id = :id',
            ['id' => $id],
        );
        $servedValues = array_map(static fn (ItemState $s): string => $s->value, ItemState::SERVED);
        if (false === $row || ('submitted' !== $row['state'] && !\in_array($row['state'], $servedValues, true))) {
            throw $this->createNotFoundException();
        }
        /** @var User $user */
        $user = $this->getUser();
        if (!$this->scopeProvider->allowsRegion($this->scopeProvider->scopeFor($user), null !== $row['region_id'] ? (int) $row['region_id'] : null)) {
            throw $this->createAccessDeniedException('Out of moderation scope.');
        }
        /** @var array<string,mixed> $attrs */
        $attrs = json_decode((string) $row['attributes'], true) ?: [];

        $editForm = $this->container->get('form.factory')->createNamedBuilder('route_edit', \Symfony\Component\Form\Extension\Core\Type\FormType::class, null, [
            'action' => $this->generateUrl('moderate_routes_edit'),
        ])
            ->add('route_id', \Symfony\Component\Form\Extension\Core\Type\HiddenType::class, ['data' => (string) $id])
            ->add('note', \Symfony\Component\Form\Extension\Core\Type\TextareaType::class, ['required' => false, 'data' => $attrs['note'] ?? null])
            ->setMethod('POST')->getForm();

        $suggested = SurfaceVocabulary::suggestFromProfile($attrs['surfaces'] ?? null);

        // Retire only applies to an already-active (unverified/verified) route —
        // a submitted proposal is retired via decide()'s three-way form instead.
        $retireForm = \in_array($row['state'], $servedValues, true)
            ? $this->container->get('form.factory')->createNamedBuilder('route_decision', \Symfony\Component\Form\Extension\Core\Type\FormType::class, null, [
                'action' => $this->generateUrl('moderate_routes_decide'),
                'method' => 'POST',
            ])
                ->add('route_id', \Symfony\Component\Form\Extension\Core\Type\HiddenType::class, ['data' => (string) $id])
                ->add('decision', \Symfony\Component\Form\Extension\Core\Type\HiddenType::class, ['data' => 'retire'])
                ->add('note', \Symfony\Component\Form\Extension\Core\Type\TextareaType::class, ['required' => false])
                ->getForm()
            : null;

        return $this->render('moderate_routes/detail.html.twig', [
            'nav_active' => 'moderate_routes',
            'route' => [
                'id' => $id, 'name' => (string) $row['name'], 'geom' => $row['geom'],
                'km' => round(((int) $row['distance_m']) / 1000, 1), 'ascent' => $row['ascent_m'],
                'regionId' => $row['region_id'], 'state' => $row['state'], 'attributes' => $attrs,
            ],
            'active_in_region' => $this->moderation->activeCountForRegion(null === $row['region_id'] ? null : (int) $row['region_id']),
            'region_cap' => $this->moderation->regionCap(),
            'suggested_surface' => $suggested,
            'suggestions' => array_values(array_filter($this->queue->pendingSuggestions($this->scopeProvider->scopeFor($user), null), static fn (array $s): bool => $s['routeId'] === $id)),
            'edit_form' => $editForm->createView(),
            'retire_form' => $retireForm?->createView(),
            'page_title' => 'moderate_routes.meta_title',
            'page_description' => 'moderate_routes.meta_description',
        ]);
    }

    #[Route('/moderate/routes/edit', name: 'moderate_routes_edit', methods: ['POST'])]
    public function edit(Request $request): Response
    {
        // The form's own CSRF token (form name → default token id), rendered by
        // form_end() inside the route_edit[] namespace — not a top-level _token.
        $payload = (array) $request->request->all('route_edit');
        if (!$this->isCsrfTokenValid('route_edit', (string) ($payload['_token'] ?? ''))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        /** @var User $curator */
        $curator = $this->getUser();
        $id = (int) ($payload['route_id'] ?? 0);
        unset($payload['route_id'], $payload['_token']);

        try {
            $this->moderation->editMetadata($id, $payload, $curator);
            $this->addFlash('success', 'moderate_routes.flash.edited');
        } catch (OutOfScopeException) {
            throw $this->createAccessDeniedException('Out of moderation scope.');
        } catch (\InvalidArgumentException|\LogicException) {
            $this->addFlash('danger', 'moderate_routes.error.undecidable');
        }

        return $this->redirectToRoute('moderate_routes_detail', ['id' => $id]);
    }

    /**
     * Trash (moderation-feedback spec M9): an immediate, permanent hard
     * delete of a route correction (any status) or a route proposal (ONLY
     * while `submitted` or `rejected` — RouteModerationService enforces the
     * guardrail). Audited content-free; never sends the rider a message.
     * Always returns to the index — a trashed route has no detail page left
     * to redirect back to.
     */
    #[Route('/moderate/routes/trash', name: 'moderate_routes_trash', methods: ['POST'])]
    public function trash(Request $request): Response
    {
        $this->validateCsrf($request, 'route-trash');

        $kind = (string) $request->request->get('kind');
        $id = (int) $request->request->get('id');
        /** @var User $curator */
        $curator = $this->getUser();

        try {
            match ($kind) {
                'correction' => $this->moderation->trashSuggestion($id, $curator),
                'proposal' => $this->moderation->trashProposal($id, $curator),
                default => throw new \InvalidArgumentException('Unknown trash kind.'),
            };
            $this->addFlash('success', 'moderate.trash.done');
        } catch (OutOfScopeException) {
            throw $this->createAccessDeniedException('Out of moderation scope.');
        } catch (TrashBlockedException) {
            $this->addFlash('danger', 'moderate.trash.blocked');
        } catch (\InvalidArgumentException) {
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
