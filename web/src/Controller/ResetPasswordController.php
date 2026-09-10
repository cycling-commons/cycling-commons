<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Controller;

use App\Entity\User;
use App\Form\ChangePasswordFormType;
use App\Form\ResetPasswordRequestFormType;
use App\Repository\UserRepository;
use App\Routing\LocalePrefix;
use App\Security\PseudonymousKey;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use SymfonyCasts\Bundle\ResetPassword\Controller\ResetPasswordControllerTrait;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

/**
 * @see docs/specs/account-and-auth.md §2
 *
 * @api
 */
#[Route(LocalePrefix::PATHS)]
final class ResetPasswordController extends AbstractController
{
    use ResetPasswordControllerTrait;

    public function __construct(
        private readonly ResetPasswordHelperInterface $resetPasswordHelper,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/reset-password', name: 'reset_password_request', methods: ['GET', 'POST'])]
    public function request(
        Request $request,
        MailerInterface $mailer,
        UserRepository $userRepository,
        RateLimiterFactoryInterface $passwordResetLimiter,
        #[Autowire('%kernel.secret%')]
        string $secret,
    ): Response {
        $form = $this->createForm(ResetPasswordRequestFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string $email */
            $email = $form->get('email')->getData();

            // Quota BEFORE the lookup, and the over-quota answer is the same
            // check-your-inbox page a real request gets. Both matter: an
            // anonymous caller must not learn from a 429 that the address
            // exists, and this page already refuses to reveal that anywhere
            // else (see processSendingPasswordResetEmail, which redirects to
            // the same place for an unknown address).
            if (!$passwordResetLimiter->create(self::anonKey($request->getClientIp() ?? 'unknown', $secret))->consume()->isAccepted()) {
                return $this->redirectToRoute('check_email');
            }

            return $this->processSendingPasswordResetEmail($email, $mailer, $userRepository);
        }

        return $this->render('security/reset_password_request.html.twig', [
            'requestForm' => $form,
            'page_title' => 'meta.reset_request_title',
            'page_description' => 'meta.reset_request_description',
        ]);
    }

    /**
     * Limiter key for an anonymous caller: a keyed hash, never the address.
     *
     * @see PseudonymousKey
     */
    public static function anonKey(string $ip, string $secret): string
    {
        return PseudonymousKey::limiter('password-reset', $ip, $secret);
    }

    #[Route('/reset-password/check-email', name: 'check_email', methods: ['GET'])]
    public function checkEmail(): Response
    {
        // Fake token so timing cannot reveal whether an account exists.
        if (null === ($resetToken = $this->getTokenObjectFromSession())) {
            $resetToken = $this->resetPasswordHelper->generateFakeResetToken();
        }

        return $this->render('security/reset_password_check_email.html.twig', [
            'resetToken' => $resetToken,
            'page_title' => 'meta.reset_check_email_title',
            'page_description' => 'meta.reset_check_email_description',
        ]);
    }

    #[Route('/reset-password/reset/{token}', name: 'reset_password', methods: ['GET', 'POST'])]
    public function reset(
        Request $request,
        UserPasswordHasherInterface $userPasswordHasher,
        ?string $token = null,
    ): Response {
        if ($token) {
            // Drop token from the URL so it cannot leak via Referer.
            $this->storeTokenInSession($token);

            return $this->redirectToRoute('reset_password');
        }

        $token = $this->getTokenFromSession();

        if (null === $token) {
            throw $this->createNotFoundException('No reset password token found in the URL or in the session.');
        }

        try {
            /** @var User $user */
            $user = $this->resetPasswordHelper->validateTokenAndFetchUser($token);
        } catch (ResetPasswordExceptionInterface $e) {
            $this->addFlash('reset_password_error', sprintf(
                '%s - %s',
                'There was a problem validating your reset request.',
                $e->getReason(),
            ));

            return $this->redirectToRoute('reset_password_request');
        }

        $form = $this->createForm(ChangePasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->resetPasswordHelper->removeResetRequest($token);

            /** @var string $plainPassword */
            $plainPassword = $form->get('plainPassword')->getData();

            $user->setPassword($userPasswordHasher->hashPassword($user, $plainPassword));
            // docs/specs/account-and-auth.md §3 — reset clears lockout so the owner can sign in.
            $user->setLockedUntil(null);
            $user->setFailedLoginAttempts(0);
            $this->entityManager->flush();

            $this->cleanSessionAfterReset();

            $this->addFlash('success', 'flash.password_reset');

            return $this->redirectToRoute('login');
        }

        return $this->render('security/reset_password.html.twig', [
            'resetForm' => $form,
            'page_title' => 'meta.reset_new_title',
            'page_description' => 'meta.reset_new_description',
        ]);
    }

    private function processSendingPasswordResetEmail(
        string $emailFormData,
        MailerInterface $mailer,
        UserRepository $userRepository,
    ): RedirectResponse {
        $user = $userRepository->findByEmail($emailFormData);

        if (!$user) {
            return $this->redirectToRoute('check_email');
        }

        try {
            $resetToken = $this->resetPasswordHelper->generateResetToken($user);
        } catch (ResetPasswordExceptionInterface $e) {
            return $this->redirectToRoute('check_email');
        }

        $email = (new TemplatedEmail())
            ->from(new Address('noreply@cyclingcommons.org', 'Cycling Commons'))
            ->to(new Address($user->getEmail(), $user->getDisplayName()))
            ->subject('Your Cycling Commons password reset link')
            ->htmlTemplate('emails/reset_password.html.twig')
            ->context(['resetToken' => $resetToken]);

        $mailer->send($email);

        $this->storeTokenInSession($resetToken->getToken());
        $this->setTokenObjectInSession($resetToken);

        return $this->redirectToRoute('check_email');
    }
}
