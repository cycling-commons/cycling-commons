<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Account\UnitFormatter;
use App\Contribution\RouteProposalService;
use App\Entity\User;
use App\Form\ProposeRouteType;
use App\Routing\LocalePrefix;
use App\Service\ContributionReceipt;
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
 * Route-proposal intake — never the item pipeline.
 *
 * @see docs/specs/route-domain.md §4
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
    ) {
    }

    #[Route('/propose-route', name: 'propose_route')]
    #[IsGranted('ROLE_USER')]
    public function propose(Request $request): Response
    {
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
        ]);
    }
}
