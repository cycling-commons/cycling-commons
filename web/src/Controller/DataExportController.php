<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

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
 * "Download my data" (docs/specs/account-and-auth.md §11) — GDPR Art. 15 and
 * Art. 20 in one ZIP.
 *
 * POST, not GET, and behind the current password. This is the single request
 * that assembles everything the app knows about a rider into one file, which
 * makes it the most valuable thing an attacker on a borrowed session could ask
 * for — more valuable than any individual page it draws from, because it
 * removes the work of collecting them. Re-authentication turns "left a laptop
 * unlocked" back into "knows the password", and the POST keeps the whole
 * archive out of a URL that could be prefetched, linked or logged.
 *
 * @api Instantiated by Symfony's router — `@api` tells Psalm this is a live
 *      entry point, not dead code.
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

        // Checked BEFORE the password, and it is the password attempt that is
        // being throttled as much as the export: this endpoint would otherwise
        // be an unmetered oracle for guessing the password of an account whose
        // session you already hold.
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
        // Belt and braces on top of the POST: an archive of somebody's whole
        // account must not sit in a shared cache or a proxy's disk.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
