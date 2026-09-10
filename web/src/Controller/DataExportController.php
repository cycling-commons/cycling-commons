<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Account\DataExportService;
use App\Entity\User;
use App\Routing\LocalePrefix;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Download my data" — POST + current password; limiter before the password check.
 *
 * @see docs/specs/account-and-auth.md §11
 *
 * @api
 */
#[Route(LocalePrefix::PATHS)]
#[IsGranted('ROLE_USER')]
final class DataExportController extends AbstractController
{
    public function __construct(
        private readonly DataExportService $exports,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/settings/export', name: 'settings_export', methods: ['POST'])]
    public function export(Request $request, RateLimiterFactoryInterface $dataExportLimiter): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('data_export', $request->request->get('_token'))) {
            $this->addFlash('export_error', 'flash.invalid_token');

            return $this->redirectToRoute('settings', ['tab' => 'security']);
        }

        // docs/specs/account-and-auth.md §11 — limiter before password (no unmetered oracle).
        if (!$dataExportLimiter->create('user-'.(string) $user->getId())->consume()->isAccepted()) {
            $this->addFlash('export_error', 'flash.export_rate_limited');

            return $this->redirectToRoute('settings', ['tab' => 'security']);
        }

        $password = (string) $request->request->get('current_password', '');
        if ('' === $password || !$this->passwordHasher->isPasswordValid($user, $password)) {
            $this->addFlash('export_error', 'flash.current_password_incorrect');

            return $this->redirectToRoute('settings', ['tab' => 'security']);
        }

        $path = $this->exports->export($user);

        $response = new BinaryFileResponse($path);
        $response->deleteFileAfterSend();
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            \sprintf('cycling-commons-export-%s.zip', $this->clock->now()->format('Y-m-d')),
        );
        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
