<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Controller;

use App\Entity\User;
use App\Messaging\MessageService;
use App\Routing\LocalePrefix;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Renders the authenticated user's messages dashboard (moderation-feedback
 * spec M3): decision outcomes, curator notes, and rider replies, newest
 * first.
 *
 * @api Instantiated by Symfony's router — `@api` tells Psalm this is a live
 *      entry point, not dead code.
 */
#[Route(LocalePrefix::PATHS)]
#[IsGranted('ROLE_USER')]
final class MessagesController extends AbstractController
{
    #[Route('/messages', name: 'messages')]
    public function index(MessageService $messages): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $userId = (int) $user->getId();

        // Fetch BEFORE marking read, so the template can still flag which
        // rows were new to this visit via isRead().
        $list = $messages->listFor($userId);
        $messages->markAllRead($userId);

        return $this->render('messages/index.html.twig', [
            'page_title' => 'meta.messages_title',
            'page_description' => 'meta.messages_description',
            'nav_active' => '',
            'cc_user' => $user,
            'messages' => $list,
        ]);
    }
}
