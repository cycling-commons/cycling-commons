<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Account\UnitFormatter;
use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\ItemState;
use App\Catalog\ItemType;
use App\Catalog\RouteMetadata;
use App\Contribution\RoutePhotoService;
use App\Contribution\RouteProposalService;
use App\Entity\User;
use App\Form\ProposeRouteType;
use App\Media\MediaClaimService;
use App\Media\PhotoPlace;
use App\Media\PhotoValidator;
use App\Moderation\AlreadyDecidedException;
use App\Moderation\NotTheSubmitterException;
use App\Moderation\RouteQueue;
use App\Routing\LocalePrefix;
use App\Service\ContributionReceipt;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Route-proposal intake, the proposer's edit while it waits, and photos for
 * a live route, never the item pipeline.
 *
 * @see docs/specs/route-domain.md §4, §4.5, §4.6
 *
 * @api
 */
#[Route(LocalePrefix::PATHS)]
final class ProposeRouteController extends AbstractController
{
    public function __construct(
        private readonly RouteProposalService $proposals,
        private readonly TranslatorInterface $translator,
        private readonly UnitFormatter $units,
        private readonly RoutePhotoService $photos,
        private readonly EntityManagerInterface $em,
        private readonly MediaClaimService $claims,
        private readonly RouteQueue $routeQueue,
    ) {
    }

    #[Route('/propose-route', name: 'propose_route')]
    #[IsGranted('ROLE_USER')]
    public function propose(Request $request): Response
    {
        // `?route=<id>`: the same page adds photos to a live route (route-domain.md §4.5).
        $routeParam = (string) $request->query->get('route', '');
        if ('' !== $routeParam) {
            return $this->routePhotos($request, $routeParam);
        }

        $form = $this->createForm(ProposeRouteType::class);
        $form->handleRequest($request);

        $receipt = null;
        $proposedRouteId = null;
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array<string, mixed> $data */
            $data = $form->getData();
            /** @var UploadedFile $gpx */
            $gpx = $data['gpx'];
            /** @var User $user */
            $user = $this->getUser();

            try {
                $route = $this->proposals->propose($gpx->getContent(), $data, $user);
                $proposedRouteId = (int) $route->getId();
                $receipt = new ContributionReceipt(
                    reference: sprintf('CC-R%05d', $proposedRouteId),
                    kind: 'route',
                    persisted: true,
                    submittedAt: new \DateTimeImmutable(),
                );
            } catch (TooManyRequestsHttpException) {
                $this->addFlash('error', 'contribute.error.rate_limited');
            } catch (\InvalidArgumentException $e) {
                $form->addError(new FormError($this->translator->trans($e->getMessage(), [
                    '%min%' => $this->units->distance(RouteProposalService::MIN_RAW_M / 1000, 0),
                    '%max%' => $this->units->distance(RouteProposalService::MAX_RAW_M / 1000, 0),
                ])));
            }
        }

        return $this->render('contribute/propose_route.html.twig', [
            'page_title' => 'meta.propose_route_title',
            'page_description' => 'meta.propose_route_description',
            'nav_active' => 'contribute',
            'receipt' => $receipt,
            // The receipt's reference links to this route's card on the rider's Contributions.
            'proposed_route_id' => $proposedRouteId,
            'form' => null !== $receipt ? null : $form,
            'photo_route' => null,
            'edit_route' => null,
        ]);
    }

    /**
     * The proposer's own edit of a route proposal while it waits for review:
     * the proposal form, prefilled, with the GPX optional. Anyone else's
     * proposal, or an id that names none, is a 404. A proposal a curator has
     * decided sends its proposer back to their Contributions with a notice.
     * The desk reads the row itself, so the curator sees this version. The
     * photos already sent are shown, and more can be added up to the cap of
     * 6 through the same uploader as a first proposal; they are decided with
     * the proposal.
     *
     * @see docs/specs/route-domain.md §4.6
     */
    #[Route('/propose-route/{id}/edit', name: 'propose_route_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function edit(int $id, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $route = $this->em->find(RecommendedRoute::class, $id);
        if (null === $route || null === $route->getProposedBy() || $route->getProposedBy() !== $user->getId()) {
            throw $this->createNotFoundException('No route proposal of yours.');
        }
        $back = $this->redirectToRoute('profile', ['letter' => ItemType::QualityRides->letter(), '_fragment' => 'route-'.$id], Response::HTTP_SEE_OTHER);
        if (ItemState::Submitted !== $route->getState()) {
            $this->addFlash('notice', 'flash.route_edit_too_late');

            return $back;
        }

        // Read before the save: a refused save rolls back, so this stays true.
        $photoRoom = max(0, MediaClaimService::MAX_PER_SUBMISSION - $this->claims->routePhotoCount($id, null));
        $sentPhotos = $this->routeQueue->proposalPhotos($id);

        $form = $this->createForm(ProposeRouteType::class, [
            RouteMetadata::NAME_FIELD => $route->getName(),
            ...RouteMetadata::formValues($route->getAttributes()),
        ], ['proposal_edit' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array<string, mixed> $data */
            $data = $form->getData();
            $gpx = $data['gpx'] ?? null;

            try {
                $this->proposals->revise($id, $gpx instanceof UploadedFile ? $gpx->getContent() : null, $data, $user);
                $this->addFlash('success', 'flash.route_proposal_saved');

                return $back;
            } catch (AlreadyDecidedException) {
                $this->addFlash('notice', 'flash.route_edit_too_late');

                return $back;
            } catch (NotTheSubmitterException) {
                throw $this->createNotFoundException('No route proposal of yours.');
            } catch (TooManyRequestsHttpException) {
                $this->addFlash('error', 'contribute.error.rate_limited');
            } catch (\InvalidArgumentException $e) {
                $form->addError(new FormError($this->translator->trans($e->getMessage(), [
                    '%min%' => $this->units->distance(RouteProposalService::MIN_RAW_M / 1000, 0),
                    '%max%' => $this->units->distance(RouteProposalService::MAX_RAW_M / 1000, 0),
                ])));
            }
        }

        return $this->render('contribute/propose_route.html.twig', [
            'page_title' => 'meta.propose_route_edit_title',
            'page_description' => 'meta.propose_route_edit_description',
            'nav_active' => 'contribute',
            'receipt' => null,
            'form' => $form,
            'photo_route' => null,
            'edit_route' => [
                'id' => $id,
                'name' => $route->getName(),
                // Where the uploader stores a photo until a new GPX is chosen (photo-uploads.md §5i).
                'pin' => $this->pinOf($id),
                'photos' => $sentPhotos,
                'photo_room' => $photoRoom,
            ],
        ]);
    }

    /**
     * Photos for a live route: a photo correction on the Routes desk, applied
     * at once for a curator in their areas.
     *
     * @see docs/specs/route-domain.md §4.5
     * @see docs/specs/photo-uploads.md §5i
     */
    private function routePhotos(Request $request, string $routeParam): Response
    {
        $route = ctype_digit($routeParam) ? $this->em->find(RecommendedRoute::class, (int) $routeParam) : null;
        if (null === $route || !\in_array($route->getState(), ItemState::SERVED, true)) {
            throw $this->createNotFoundException('No live route.');
        }

        $form = $this->createForm(ProposeRouteType::class, null, ['route_photos' => true]);
        $form->handleRequest($request);

        $receipt = null;
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array<string, mixed> $data */
            $data = $form->getData();
            /** @var User $user */
            $user = $this->getUser();
            $note = $data['note'] ?? null;

            try {
                $result = $this->photos->submit($route, $user, $data['mediaIds'] ?? null, $data['mediaAlts'] ?? null, \is_string($note) ? $note : null);
                $receipt = new ContributionReceipt(
                    reference: sprintf('CC-R%05d', (int) $route->getId()),
                    kind: 'route',
                    persisted: true,
                    submittedAt: new \DateTimeImmutable(),
                    applied: $result['applied'],
                );
            } catch (TooManyRequestsHttpException) {
                $this->addFlash('error', 'contribute.error.rate_limited');
            } catch (\InvalidArgumentException $e) {
                $form->addError(new FormError($this->translator->trans($e->getMessage())));
            }
        }

        $pin = $this->pinOf((int) $route->getId());
        // Only what the map itself would show (PhotoValidator, photo-uploads.md §5h).
        $shown = PhotoValidator::sift($route->getAttributes(), PhotoPlace::route($pin[0] ?? null, $pin[1] ?? null))['attributes'];

        return $this->render('contribute/propose_route.html.twig', [
            'page_title' => 'meta.route_photos_title',
            'page_description' => 'meta.route_photos_description',
            'nav_active' => 'contribute',
            'receipt' => $receipt,
            'form' => null !== $receipt ? null : $form,
            'photo_route' => [
                'id' => (int) $route->getId(),
                'name' => $route->getName(),
                'pin' => $pin,
                'photos' => self::galleryOf($shown),
            ],
            'edit_route' => null,
        ]);
    }

    /**
     * A point on the route, `[lat, lng]`: where its photos are stored
     * (the upload's continent, photo-uploads.md §3), `ST_PointOnSurface(geom)`
     * as PhotoPlace::route() uses.
     *
     * @return array{0: float, 1: float}|null
     */
    private function pinOf(int $routeId): ?array
    {
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT ST_Y(ST_PointOnSurface(geom)) AS lat, ST_X(ST_PointOnSurface(geom)) AS lng FROM recommended_route WHERE id = :id',
            ['id' => $routeId],
        );

        return \is_array($row) && is_numeric($row['lat']) && is_numeric($row['lng']) ? [(float) $row['lat'], (float) $row['lng']] : null;
    }

    /**
     * The photos already on the route, so the rider sees what is there.
     *
     * @param array<string, mixed> $attributes
     *
     * @return list<array<string, mixed>>
     */
    private static function galleryOf(array $attributes): array
    {
        $photos = $attributes['photos'] ?? (isset($attributes['photo']) ? [$attributes['photo']] : []);

        return \is_array($photos) ? array_values(array_filter($photos, static fn (mixed $p): bool => \is_array($p) && \is_string($p['sm'] ?? null))) : [];
    }
}
