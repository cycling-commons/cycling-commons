<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Routing\LocalePrefix;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Permanent redirects from the rider pages' first paths to their home under
 * /account, and from the submissions history to its home under
 * /moderate/submissions. A link in a sent email or a bookmark still lands,
 * with its query string. GET only: a form always posts to the current path.
 *
 * @see docs/specs/account-and-auth.md §8
 *
 * @api
 */
#[Route(LocalePrefix::PATHS)]
final class MovedAccountPathsController extends AbstractController
{
    #[Route('/profile', name: 'moved_profile', methods: ['GET'])]
    public function profile(Request $request): Response
    {
        return $this->moved('profile', $request);
    }

    #[Route('/profile/reports', name: 'moved_my_reports', methods: ['GET'])]
    public function reports(Request $request): Response
    {
        return $this->moved('my_reports', $request);
    }

    #[Route('/messages', name: 'moved_messages', methods: ['GET'])]
    public function messages(Request $request): Response
    {
        return $this->moved('messages', $request);
    }

    #[Route('/settings', name: 'moved_settings', methods: ['GET'])]
    public function settings(Request $request): Response
    {
        return $this->moved('settings', $request);
    }

    #[Route('/moderate/history', name: 'moved_moderate_history', methods: ['GET'])]
    public function moderateHistory(Request $request): Response
    {
        return $this->moved('moderate_history', $request);
    }

    private function moved(string $route, Request $request): Response
    {
        return $this->redirectToRoute($route, $request->query->all(), Response::HTTP_MOVED_PERMANENTLY);
    }
}
