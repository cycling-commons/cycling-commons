<?php

// SPDX-License-Identifier: AGPL-3.0-only

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

    /**
     * The one sentence of the drawer that is personal: the day this rider
     * stood here (docs/specs/data-provider-hierarchy.md §6.7.3). Its own
     * fragment, private and no-store, because a shared cache holding one
     * rider's sentence and serving it to another is the worst failure this
     * feature could have. Anonymous visitors hold no confirmations, so the
     * answer is empty before the database is asked anything; the drawer
     * never requests it for them either. This is the only route the drawer
     * calls that reads the session: the drawer body stays cacheable.
     */
    #[Route('/items/{id}/mine', name: 'item_mine', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function mine(int $id): JsonResponse
    {
        $response = new JsonResponse(['confirmed_at' => null]);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $response;
        }
        $at = $this->em->getConnection()->fetchOne(
            "SELECT MAX(created_at) FROM item_confirmation WHERE item_id = :item AND user_id = :user AND source <> 'form'",
            ['item' => $id, 'user' => (int) $user->getId()],
        );
        if (\is_string($at) && '' !== $at) {
            $response->setData(['confirmed_at' => (new \DateTimeImmutable($at))->format('Y-m-d')]);
        }

        return $response;
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
     * @return array{stances: array<string, int>, total: int, byCurator: bool, mine: ?string, mineSource: ?string, stanceKind: string}
     */
    private function payload(Item $item, ?User $user): array
    {
        return [
            ...$this->confirmations->snapshot($item, $user),
            'stanceKind' => ItemConfirmationService::stanceKindFor($item),
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
