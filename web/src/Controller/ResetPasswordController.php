<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Controller;

use App\Entity\User;
use App\Form\ChangePasswordFormType;
use App\Form\ResetPasswordRequestFormType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use SymfonyCasts\Bundle\ResetPassword\Controller\ResetPasswordControllerTrait;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

/**
 * @api Instantiated by Symfony's router; never referenced from code.
 */
#[Route('/reset-password')]
final class ResetPasswordController extends AbstractController
{
    use ResetPasswordControllerTrait;

    public function __construct(
        private readonly ResetPasswordHelperInterface $resetPasswordHelper,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'reset_password_request', methods: ['GET', 'POST'])]
    public function request(Request $request, MailerInterface $mailer, UserRepository $userRepository): Response
    {
        $form = $this->createForm(ResetPasswordRequestFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string $email */
            $email = $form->get('email')->getData();

            return $this->processSendingPasswordResetEmail($email, $mailer, $userRepository);
        }

        return $this->render('security/reset_password_request.html.twig', [
            'requestForm' => $form,
            'page_title' => 'meta.reset_request_title',
            'page_description' => 'meta.reset_request_description',
        ]);
    }

    #[Route('/check-email', name: 'check_email', methods: ['GET'])]
    public function checkEmail(): Response
    {
        // If the user arrives here without a token object in the session, they came directly.
        // Generate a fake token so timing cannot reveal if an account exists.
        if (null === ($resetToken = $this->getTokenObjectFromSession())) {
            $resetToken = $this->resetPasswordHelper->generateFakeResetToken();
        }

        return $this->render('security/reset_password_check_email.html.twig', [
            'resetToken' => $resetToken,
            'page_title' => 'meta.reset_check_email_title',
            'page_description' => 'meta.reset_check_email_description',
        ]);
    }

    #[Route('/reset/{token}', name: 'reset_password', methods: ['GET', 'POST'])]
    public function reset(
        Request $request,
        UserPasswordHasherInterface $userPasswordHasher,
        ?string $token = null,
    ): Response {
        if ($token) {
            // Store token in session and redirect to remove it from the URL (prevent leakage via Referer header).
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
            // Token must be removed before persisting the new password.
            $this->resetPasswordHelper->removeResetRequest($token);

            /** @var string $plainPassword */
            $plainPassword = $form->get('plainPassword')->getData();

            $user->setPassword($userPasswordHasher->hashPassword($user, $plainPassword));
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

        // Do not reveal whether a user account was found or not.
        if (!$user) {
            return $this->redirectToRoute('check_email');
        }

        try {
            $resetToken = $this->resetPasswordHelper->generateResetToken($user);
        } catch (ResetPasswordExceptionInterface $e) {
            // If a reset token was recently generated, we redirect without sending a new email.
            return $this->redirectToRoute('check_email');
        }

        $email = (new TemplatedEmail())
            ->from(new Address('noreply@cyclingcommons.org', 'Cycling Commons'))
            ->to(new Address($user->getEmail(), $user->getDisplayName()))
            ->subject('Your Cycling Commons password reset link')
            ->htmlTemplate('emails/reset_password.html.twig')
            ->context(['resetToken' => $resetToken]);

        $mailer->send($email);

        // Store the plain token string for later validation in the reset step.
        $this->storeTokenInSession($resetToken->getToken());
        // Store the token object (expiry metadata) so the check-email page can show the expiry time.
        // Note: setTokenObjectInSession() calls clearToken() on the object, so call it after storeTokenInSession().
        $this->setTokenObjectInSession($resetToken);

        return $this->redirectToRoute('check_email');
    }
}
