<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Controller;

use App\Entity\User;
use App\Form\TwoFactorSetupType;
use Doctrine\ORM\EntityManagerInterface;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\SvgWriter;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Self-service TOTP enrolment. Reachable by any fully authenticated user (and forced on
 * elevated roles by {@see \App\Security\LoginSuccessHandler}).
 *
 * Security note: the freshly generated secret is held in the session — NOT on the User entity —
 * until the user proves they scanned it correctly by entering a valid code. Only then is the
 * secret persisted, 2FA enabled, and one-time backup codes issued (shown exactly once).
 *
 * @api Instantiated by Symfony's router; never referenced from code.
 *      `@api` tells Psalm this is a live entry point, not dead code.
 */
final class TwoFactorController extends AbstractController
{
    /** Session key holding the not-yet-confirmed TOTP secret. */
    private const string PENDING_SECRET_KEY = '2fa_pending_secret';

    /** Number of single-use backup codes to issue. */
    private const int BACKUP_CODE_COUNT = 8;

    #[Route('/2fa/setup', name: '2fa_setup')]
    public function setup(
        Request $request,
        TotpAuthenticatorInterface $totpAuthenticator,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $session = $request->getSession();

        // Reuse a pending secret across GET/POST so the QR the user scanned stays valid.
        $pendingSecret = $session->get(self::PENDING_SECRET_KEY);
        if (!\is_string($pendingSecret) || '' === $pendingSecret) {
            $pendingSecret = $totpAuthenticator->generateSecret();
            $session->set(self::PENDING_SECRET_KEY, $pendingSecret);
        }

        // Attach the pending secret to the in-memory user ONLY to derive the QR content and to
        // validate the entered code. We never flush() on this path, so it does not hit the DB
        // until the code is confirmed below.
        $user->setTotpSecret($pendingSecret);

        $form = $this->createForm(TwoFactorSetupType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string $code */
            $code = $form->get('code')->getData();

            if ($totpAuthenticator->checkCode($user, $code)) {
                $backupCodes = $this->generateBackupCodes();

                $user->setTotpSecret($pendingSecret);
                $user->setTwoFaEnabled(true);
                // Store only SHA-256 hashes; the plaintext codes are shown once below.
                $user->setBackupCodes(array_map(static fn (string $c): string => hash('sha256', $c), $backupCodes));
                $entityManager->flush();

                $session->remove(self::PENDING_SECRET_KEY);

                return $this->render('security/2fa_setup.html.twig', [
                    'backup_codes' => $backupCodes,
                    'setup_complete' => true,
                    'page_title' => 'Cycling Commons — Two-factor enabled',
                    'page_description' => 'Two-factor authentication is now protecting your Cycling Commons account.',
                ]);
            }

            $form->get('code')->addError(new FormError(
                'That code did not match. Check your authenticator app and try again.'
            ));
        }

        return $this->render('security/2fa_setup.html.twig', [
            'form' => $form,
            'setup_complete' => false,
            'qr_code_uri' => $this->buildQrCodeDataUri($totpAuthenticator->getQRContent($user)),
            'manual_secret' => $pendingSecret,
            'page_title' => 'Cycling Commons — Set up two-factor',
            'page_description' => 'Add an authenticator app to protect your Cycling Commons account.',
        ]);
    }

    /**
     * Render the otpauth:// URI as an inline SVG data-URI for an <img> tag.
     * SVG (not PNG) so we don't require the GD/Imagick PHP extension — the QR
     * still scans identically and stays crisp at any size.
     */
    private function buildQrCodeDataUri(string $otpauthUri): string
    {
        return (new Builder())->build(
            writer: new SvgWriter(),
            data: $otpauthUri,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 220,
            margin: 10,
        )->getDataUri();
    }

    /**
     * Generate cryptographically random single-use backup codes.
     * Stored as plain strings to match the entity's BackupCodeInterface::isBackupCode()
     * comparison (strict in_array against the stored list).
     *
     * @return list<string>
     */
    private function generateBackupCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < self::BACKUP_CODE_COUNT; ++$i) {
            // 8 hex chars per code; grouped XXXX-XXXX for legibility.
            $raw = bin2hex(random_bytes(4));
            $codes[] = substr($raw, 0, 4).'-'.substr($raw, 4, 4);
        }

        return $codes;
    }
}
