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
 * The rider confirmation loop on a non-votable item's map drawer: drinking-water
 * potability, or a plain existence confirmation for a utility (services,
 * hazards, shelter, getting-there). Unlocalized `/items/{id}/…` JSON API
 * (matches the route community + GPX endpoints).
 *
 * Tallies are PUBLIC — the snapshot GET works for anonymous viewers (counts
 * only, no personal stance/token). Recording requires a logged-in ROLE_USER
 * (a clean 401, not a login redirect) + stateless CSRF.
 *
 * @api Instantiated by Symfony's router; called by assets/map/map.js.
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
     * NEVER make this response publicly cacheable. It sits in security.yaml's
     * PUBLIC_ACCESS cluster beside endpoints that are there FOR cacheability,
     * but unlike them it is user-varying: `mine` is the caller's own stance and
     * the payload carries a CSRF token. Its (default) `private` Cache-Control
     * is load-bearing — setPublic() here would hand one rider's stance and
     * token to everyone behind a shared cache (frontend review 2026-08-09 #3).
     */
    #[Route('/items/{id}/confirmations', name: 'item_confirmations', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function snapshot(int $id, CsrfTokenManagerInterface $csrf): JsonResponse
    {
        $item = $this->confirmableItem($id);
        $user = $this->getUser();
        $user = $user instanceof User ? $user : null;

        $payload = $this->payload($item, $user);
        // A token is only useful to someone who can actually POST.
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

        try {
            $this->confirmations->record($item, $user, $stance);
        } catch (\InvalidArgumentException) {
            // Stance not offered for this item's type.
            return $this->json(['error' => 'invalid_stance'], 422);
        }

        return $this->json([...$this->payload($item, $user), 'ok' => true]);
    }

    /**
     * The public snapshot plus the stance kind ('potability' for water,
     * 'existence' for other utilities) — the shape both endpoints return.
     *
     * @return array{stances: array<string, int>, total: int, mine: ?string, stanceKind: string}
     */
    private function payload(Item $item, ?User $user): array
    {
        return [
            ...$this->confirmations->snapshot($item, $user),
            'stanceKind' => ItemType::WaterFood === ItemType::fromParam($item->getLetter()) ? 'potability' : 'existence',
        ];
    }

    /** A served, confirmable item (non-votable utility); 404 otherwise. */
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

    /** A fully-authenticated ROLE_USER, or a clean 401 (never a login redirect). */
    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$this->isGranted('ROLE_USER') || !$user instanceof User) {
            throw new HttpException(Response::HTTP_UNAUTHORIZED, 'authentication_required');
        }

        return $user;
    }
}
