<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Messaging\CuratorRoom;
use App\Messaging\CuratorRoomCategory;
use App\Messaging\CuratorRoomPin;
use App\Moderation\ModerationScopeProvider;
use App\Routing\LocalePrefix;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The curator room: the in-desk board the rulebook points at.
 *
 * Curators only, like every other desk. Unscoped on purpose: the rulebook's
 * "if an item is out of reach, ask the room" only works if the room reaches
 * past the asker's moderation area (§13.6).
 *
 * @see docs/specs/moderation-and-contribution.md §13
 *
 * @api
 */
#[Route(LocalePrefix::PATHS)]
#[IsGranted('ROLE_CURATOR')]
final class ModerateRoomController extends AbstractController
{
    public function __construct(
        private readonly CuratorRoom $room,
        private readonly ModerationScopeProvider $scopeProvider,
    ) {
    }

    #[Route('/moderate/room', name: 'moderate_room', methods: ['GET'])]
    public function index(Request $request): Response
    {
        /** @var User $curator */
        $curator = $this->getUser();
        $curatorId = (int) $curator->getId();
        $view = $this->view($request);

        $board = $this->room->board($curatorId, $view);

        // Stamped before the response renders, so the tab this page owns does
        // not badge the page you are looking at.
        $this->room->markSeen($curatorId);

        return $this->render('moderate/room.html.twig', [
            'page_title' => 'meta.moderate_room_title',
            'page_description' => 'meta.moderate_room_description',
            'nav_active' => 'moderate_room',
            'view' => $view,
            'categories' => CuratorRoomCategory::cases(),
            'pinned' => $board['pinned'],
            'posts' => $board['posts'],
            'curators' => $this->room->curators($curatorId),
            'body_max' => CuratorRoom::BODY_MAX_LENGTH,
            'mod_scope_names' => $this->scopeProvider->describe($curator),
        ]);
    }

    #[Route('/moderate/room/post', name: 'moderate_room_post', methods: ['POST'])]
    public function post(Request $request): Response
    {
        $this->assertToken($request, 'moderate-room-post');

        /** @var User $curator */
        $curator = $this->getUser();
        $view = $this->view($request);

        // Read as strings, not getInt(): the "everyone" option and the empty
        // submission box both post "", and InputBag::getInt() throws on that
        // rather than returning the default.
        $recipientId = (int) $request->request->getString('to') ?: null;
        $aboutId = (int) $request->request->getString('about') ?: null;
        $category = CuratorRoomCategory::tryFrom($request->request->getString('category'));

        try {
            $this->room->post(
                (int) $curator->getId(),
                $category,
                $recipientId,
                $request->request->getString('body'),
                $aboutId,
            );
            $this->addFlash('success', 'room.flash.posted');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->backToRoom($view);
    }

    #[Route('/moderate/room/pin', name: 'moderate_room_pin', methods: ['POST'])]
    public function pin(Request $request): Response
    {
        $this->assertToken($request, 'moderate-room-pin');

        $view = $this->view($request);
        $pin = CuratorRoomPin::tryFrom($request->request->getString('pin')) ?? CuratorRoomPin::None;

        try {
            $this->room->pin((int) $request->request->getString('id'), $pin);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->backToRoom($view);
    }

    #[Route('/moderate/room/delete', name: 'moderate_room_delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        $this->assertToken($request, 'moderate-room-delete');

        /** @var User $curator */
        $curator = $this->getUser();
        $view = $this->view($request);

        if (!$this->room->deleteOwn((int) $request->request->getString('id'), (int) $curator->getId())) {
            $this->addFlash('danger', 'room.error.not_yours');
        }

        return $this->backToRoom($view);
    }

    private function assertToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    /**
     * The active view: a category value, `direct`, `root`, or All for anything
     * else. An unknown value falls back rather than 404ing, because a stale
     * bookmark should still open the room.
     */
    private function view(Request $request): string
    {
        $raw = $request->isMethod('POST')
            ? $request->request->getString('c')
            : $request->query->getString('c');

        if (CuratorRoomCategory::VIEW_DIRECT === $raw || CuratorRoomCategory::VIEW_ROOT === $raw) {
            return $raw;
        }

        $category = CuratorRoomCategory::tryFrom($raw);

        return null !== $category ? $category->value : CuratorRoomCategory::VIEW_ALL;
    }

    private function backToRoom(string $view): Response
    {
        return $this->redirectToRoute(
            'moderate_room',
            CuratorRoomCategory::VIEW_ALL === $view ? [] : ['c' => $view],
        );
    }
}
