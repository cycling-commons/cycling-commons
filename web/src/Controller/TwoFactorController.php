<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Controller;

use App\Entity\User;
use App\Form\TwoFactorSetupType;
use App\Routing\LocalePrefix;
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
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Self-service TOTP enrolment. Secret stays in session until a valid code.
 *
 * @see docs/specs/account-and-auth.md §4
 *
 * @api
 */
#[Route(LocalePrefix::PATHS)]
final class TwoFactorController extends AbstractController
{
    /** Session key holding the not-yet-confirmed TOTP secret. */
    private const string PENDING_SECRET_KEY = '2fa_pending_secret';

    /** Number of single-use backup codes to issue. */
    private const int BACKUP_CODE_COUNT = 8;

    #[Route('/2fa/setup', name: '2fa_setup')]
    #[IsGranted('ROLE_USER')]
    public function setup(
        Request $request,
        EntityManagerInterface $entityManager,
        ?TotpAuthenticatorInterface $totpAuthenticator = null,
    ): Response {
        // Nullable: `when@dev` disables TOTP; requiring it 500s the setup page.
        if (null === $totpAuthenticator) {
            return $this->render('security/2fa_unavailable.html.twig', [
                'page_title' => 'meta.twofa_setup_title',
                'page_description' => 'meta.twofa_setup_description',
            ]);
        }
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('2FA setup requires an authenticated user.');
        }
        $session = $request->getSession();

        // Reuse across GET/POST so the scanned QR stays valid.
        $pendingSecret = $session->get(self::PENDING_SECRET_KEY);
        if (!\is_string($pendingSecret) || '' === $pendingSecret) {
            $pendingSecret = $totpAuthenticator->generateSecret();
            $session->set(self::PENDING_SECRET_KEY, $pendingSecret);
        }

        // In-memory only until a valid code — never flushed here.
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
                // docs/specs/account-and-auth.md §4 — keyed hashes; plaintext shown once.
                $user->setBackupCodes(array_map(User::hashBackupCode(...), $backupCodes));
                $entityManager->flush();

                $session->remove(self::PENDING_SECRET_KEY);

                return $this->render('security/2fa_setup.html.twig', [
                    'backup_codes' => $backupCodes,
                    'setup_complete' => true,
                    'page_title' => 'meta.twofa_enabled_title',
                    'page_description' => 'meta.twofa_enabled_description',
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
            'page_title' => 'meta.twofa_setup_title',
            'page_description' => 'meta.twofa_setup_description',
        ]);
    }

    /** SVG data-URI so QR rendering does not need GD/Imagick. */
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
     * @return list<string>
     */
    private function generateBackupCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < self::BACKUP_CODE_COUNT; ++$i) {
            // docs/specs/account-and-auth.md §4 — 80 bits; 32 bits was enumerable.
            $codes[] = implode('-', str_split(bin2hex(random_bytes(10)), 4));
        }

        return $codes;
    }
}
