<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Controller;

use App\Entity\User;
use App\Form\TwoFactorSetupType;
use App\Routing\LocalePrefix;
use App\Security\TwoFactorChangeNotice;
use Doctrine\ORM\EntityManagerInterface;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\SvgWriter;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Self-service TOTP enrolment. Secret stays in session until a valid code.
 *
 * Enrolling replaces whatever second factor the account had, so it is guarded
 * like one: an account that already has two-factor
 * proves it holds the old one (a current code or a backup code), and a
 * remember-me session confirms the password, because a REMEMBERME cookie is
 * exactly what a shared or stolen browser carries. Both checks spend the
 * shared password_reauth budget.
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
        UserPasswordHasherInterface $hasher,
        RateLimiterFactoryInterface $passwordReauthLimiter,
        TwoFactorChangeNotice $notice,
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

        // The pending secret lives on a detached copy, so the account keeps
        // its stored secret for the old-code check below and nothing a later
        // flush in this request does can persist an unconfirmed one.
        $pending = clone $user;
        $pending->setTotpSecret($pendingSecret);

        $replacing = $user->isTotpAuthenticationEnabled();
        $remembered = !$this->isGranted('IS_AUTHENTICATED_FULLY');
        $form = $this->createForm(TwoFactorSetupType::class, null, [
            'current_code' => $replacing,
            'current_password' => $remembered,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()
            && $this->provesItIsTheOwner($form, $user, $replacing, $remembered, $hasher, $passwordReauthLimiter, $totpAuthenticator)) {
            /** @var string $code */
            $code = $form->get('code')->getData();

            if ($totpAuthenticator->checkCode($pending, $code)) {
                $backupCodes = $this->generateBackupCodes();

                $user->setTotpSecret($pendingSecret);
                $user->setTwoFaEnabled(true);
                // docs/specs/account-and-auth.md §4 — keyed hashes; plaintext shown once.
                $user->setBackupCodes(array_map(User::hashBackupCode(...), $backupCodes));
                $entityManager->flush();
                $notice->send($user, $replacing);

                $session->remove(self::PENDING_SECRET_KEY);

                return $this->render('security/2fa_setup.html.twig', [
                    'backup_codes' => $backupCodes,
                    'setup_complete' => true,
                    'page_title' => 'meta.twofa_enabled_title',
                    'page_description' => 'meta.twofa_enabled_description',
                ]);
            }

            $form->get('code')->addError(new FormError('form.error_totp_mismatch'));
        }

        return $this->render('security/2fa_setup.html.twig', [
            'form' => $form,
            'setup_complete' => false,
            'replacing' => $replacing,
            'qr_code_uri' => $this->buildQrCodeDataUri($totpAuthenticator->getQRContent($pending)),
            'manual_secret' => $pendingSecret,
            'page_title' => 'meta.twofa_setup_title',
            'page_description' => 'meta.twofa_setup_description',
        ]);
    }

    /**
     * The checks that stand in for "this is the account's owner" before a
     * second factor is replaced, each putting its error on its own field.
     *
     * One try from the budget per submission that has anything to check, so a
     * script gets five guesses a quarter of an hour at the password and the
     * six-digit code together. A backup code that proves ownership is not
     * spent here: enrolling replaces every backup code a moment later.
     */
    private function provesItIsTheOwner(
        FormInterface $form,
        User $user,
        bool $replacing,
        bool $remembered,
        UserPasswordHasherInterface $hasher,
        RateLimiterFactoryInterface $passwordReauthLimiter,
        TotpAuthenticatorInterface $totpAuthenticator,
    ): bool {
        if (!$replacing && !$remembered) {
            return true;
        }
        if (!$passwordReauthLimiter->create('user-'.(string) $user->getId())->consume()->isAccepted()) {
            $form->addError(new FormError('flash.reauth_too_many'));

            return false;
        }

        $proven = true;
        if ($remembered && !$hasher->isPasswordValid($user, (string) $form->get('currentPassword')->getData())) {
            $form->get('currentPassword')->addError(new FormError('flash.current_password_incorrect'));
            $proven = false;
        }
        if ($replacing) {
            $current = trim((string) $form->get('currentCode')->getData());
            if (!$totpAuthenticator->checkCode($user, $current) && !$user->isBackupCode($current)) {
                $form->get('currentCode')->addError(new FormError('twofactor.setup.current_code_wrong'));
                $proven = false;
            }
        }

        return $proven;
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
