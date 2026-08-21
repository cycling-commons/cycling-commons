<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\ConfirmationStance;
use App\Catalog\Entity\Item;
use App\Catalog\ItemState;
use App\Catalog\ItemType;
use App\Community\ItemConfirmationService;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Item confirmation loop: public tallies; write is 401 not 302.
 *
 * @see docs/specs/moderation-and-contribution.md §10.2
 *
 * @api
 */
final class ItemConfirmationController extends AbstractController
{
    private const string CSRF_TOKEN_ID = 'item-confirm';

    public function __construct(
        private readonly ItemConfirmationService $confirmations,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Never public-cache: payload is user-varying (`mine` + CSRF token).
     *
     * @see docs/specs/account-and-auth.md §5
     */
    #[Route('/items/{id}/confirmations', name: 'item_confirmations', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function snapshot(int $id, CsrfTokenManagerInterface $csrf): JsonResponse
    {
        $item = $this->confirmableItem($id);
        $user = $this->getUser();
        $user = $user instanceof User ? $user : null;

        $payload = $this->payload($item, $user);
        if (null !== $user) {
            $payload['token'] = $csrf->getToken(self::CSRF_TOKEN_ID)->getValue();
        }

        return $this->json($payload);
    }

    #[Route('/items/{id}/confirm', name: 'item_confirm', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function confirm(int $id, Request $request): JsonResponse
    {
        $user = $this->requireUser();
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $item = $this->confirmableItem($id);
        $stance = ConfirmationStance::tryFrom((string) $request->request->get('stance'));
        if (null === $stance) {
            return $this->json(['error' => 'invalid_stance'], 422);
        }

        $wasVerified = ItemState::Verified === $item->getState();
        try {
            $this->confirmations->record($item, $user, $stance);
        } catch (\InvalidArgumentException) {
            return $this->json(['error' => 'invalid_stance'], 422);
        }

        return $this->json([
            ...$this->payload($item, $user),
            'ok' => true,
            'verified' => !$wasVerified && ItemState::Verified === $item->getState(),
        ]);
    }

    /**
     * @return array{stances: array<string, int>, total: int, mine: ?string, mineSource: ?string, stanceKind: string}
     */
    private function payload(Item $item, ?User $user): array
    {
        return [
            ...$this->confirmations->snapshot($item, $user),
            'stanceKind' => match (ItemType::fromParam($item->getLetter())) {
                ItemType::WaterFood => 'potability',
                ItemType::RoadSurface => 'accuracy',
                default => 'existence',
            },
        ];
    }

    /** Served confirmable item; 404 otherwise. */
    private function confirmableItem(int $id): Item
    {
        $item = $this->em->find(Item::class, $id);
        if (null === $item
            || !\in_array($item->getState(), ItemState::SERVED, true)
            || !ItemType::fromParam($item->getLetter())->isConfirmable()
        ) {
            throw $this->createNotFoundException('No confirmable item.');
        }

        return $item;
    }

    /** ROLE_USER, or a clean 401 (never a login redirect). */
    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$this->isGranted('ROLE_USER') || !$user instanceof User) {
            throw new HttpException(Response::HTTP_UNAUTHORIZED, 'authentication_required');
        }

        return $user;
    }
}
