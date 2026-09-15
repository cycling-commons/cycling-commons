<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Account\UnitFormatter;
use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\ItemState;
use App\Contribution\RoutePhotoService;
use App\Contribution\RouteProposalService;
use App\Entity\User;
use App\Form\ProposeRouteType;
use App\Media\PhotoPlace;
use App\Media\PhotoValidator;
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
 * Route-proposal intake, and photos for a live route, never the item pipeline.
 *
 * @see docs/specs/route-domain.md §4, §4.5
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
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array<string, mixed> $data */
            $data = $form->getData();
            /** @var UploadedFile $gpx */
            $gpx = $data['gpx'];
            /** @var User $user */
            $user = $this->getUser();

            try {
                $route = $this->proposals->propose($gpx->getContent(), $data, $user);
                $receipt = new ContributionReceipt(
                    reference: sprintf('CC-R%05d', (int) $route->getId()),
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
            'form' => null !== $receipt ? null : $form,
            'photo_route' => null,
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
