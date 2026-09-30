<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Moderation\DeskSeen;
use App\Moderation\SeenSubject;
use App\Routing\LocalePrefix;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The map drawer opened a desk item: a pending submission, or a Data finding
 * resolved on the map. JSON `{seen}`: whether this took the unseen bar off it
 * for this curator. Every other way of opening an item is recorded where its
 * page loads ({@see \App\EventSubscriber\DeskOpenedSubscriber}).
 *
 * @see docs/specs/moderation-and-contribution.md §5.2f
 *
 * @api
 */
#[Route(LocalePrefix::PATHS)]
#[IsGranted('ROLE_CURATOR')]
final class ModerateSeenController extends AbstractController
{
    /** The kinds the map drawer opens. */
    private const array ON_THE_MAP = [SeenSubject::Submission, SeenSubject::CatalogFinding];

    public function __construct(private readonly DeskSeen $seen)
    {
    }

    #[Route('/moderate/seen', name: 'moderate_seen', methods: ['POST'])]
    public function seen(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('moderate-seen', $request->request->getString('_token'))) {
            return new JsonResponse(['error' => 'csrf'], Response::HTTP_FORBIDDEN);
        }
        $subject = SeenSubject::tryFrom($request->request->getString('type'));
        $raw = $request->request->getString('id');
        if (null === $subject || !\in_array($subject, self::ON_THE_MAP, true) || 1 !== preg_match('/^[1-9]\d{0,17}$/', $raw)) {
            return new JsonResponse(['error' => 'bad_request'], Response::HTTP_BAD_REQUEST);
        }
        /** @var User $curator */
        $curator = $this->getUser();

        return new JsonResponse(['seen' => $this->seen->mark((int) $curator->getId(), $subject, (int) $raw)]);
    }
}
