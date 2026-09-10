<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Repository\UserRepository;
use App\Security\EmailVerifier;
use App\Security\PseudonymousKey;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;

/**
 * @see docs/specs/account-and-auth.md §2
 *
 * @api
 */
final class RegistrationController extends AbstractController
{
    public function __construct(private readonly EmailVerifier $emailVerifier)
    {
    }

    #[Route([
        'en' => '/register',
        'fr' => '/fr/register',
        'nl' => '/nl/register',
        'de' => '/de/register',
        'es' => '/es/register',
    ], name: 'register')]
    public function register(
        Request $request,
        UserPasswordHasherInterface $userPasswordHasher,
        EntityManagerInterface $entityManager,
        RateLimiterFactoryInterface $registrationLimiter,
        #[Autowire('%kernel.secret%')]
        string $secret,
    ): Response {
        if ($this->getUser()) {
            $this->addFlash('notice', 'flash.already_signed_in');

            return $this->redirectToRoute('home');
        }

        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Quota BEFORE anything is written or sent. One unauthenticated
            // POST creates a row and mails a confirmation link to whatever
            // address was typed, so without this a loop both fills the user
            // table and points our mail server at someone else's inbox
            // (security scan 2026-08-25). A visible error, not a silent
            // redirect: unlike the reset form this page already tells you when
            // an address is taken, so there is no existence secret to keep.
            if (!$registrationLimiter->create(self::anonKey($request->getClientIp() ?? 'unknown', $secret))->consume()->isAccepted()) {
                // A catalogue key, not a sentence: this error is form-level, and
                // form-level errors only became visible on 2026-08-27 (the page
                // rendered field errors and dropped the rest). The moment it is
                // shown it has to exist in five languages like any other copy.
                $form->addError(new FormError('security.register.error_rate_limited'));

                return $this->render('security/register.html.twig', [
                    'registrationForm' => $form,
                    'page_title' => 'meta.register_title',
                    'page_description' => 'meta.register_description',
                ], new Response('', Response::HTTP_TOO_MANY_REQUESTS));
            }

            /** @var string $plainPassword */
            $plainPassword = $form->get('plainPassword')->getData();

            $user->setPassword(
                $userPasswordHasher->hashPassword($user, $plainPassword)
            );
            $user->setRoles(['ROLE_USER']);
            $user->setEmailVerified(false);
            $user->confirmAge(new \DateTimeImmutable());

            try {
                $entityManager->persist($user);
                $entityManager->flush();
            } catch (UniqueConstraintViolationException) {
                // TOCTOU: same duplicate-email form error, never a 500.
                $form->get('email')->addError(new FormError('form.error_email_taken'));

                return $this->render('security/register.html.twig', [
                    'registrationForm' => $form,
                    'page_title' => 'meta.register_title',
                    'page_description' => 'meta.register_description',
                ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            $this->emailVerifier->sendEmailConfirmation(
                'verify_email',
                $user,
                (new TemplatedEmail())
                    ->from(new Address('noreply@cyclingcommons.org', 'Cycling Commons'))
                    ->to(new Address($user->getEmail(), $user->getDisplayName()))
                    ->subject('Confirm your Cycling Commons account')
                    ->htmlTemplate('registration/confirmation_email.html.twig')
                    ->context(['displayName' => $user->getDisplayName()])
            );

            return $this->render('security/check_email.html.twig', [
                'page_title' => 'meta.register_check_email_title',
                'page_description' => 'meta.register_check_email_description',
            ]);
        }

        return $this->render('security/register.html.twig', [
            'registrationForm' => $form,
            'page_title' => 'meta.register_title',
            'page_description' => 'meta.register_description',
        ]);
    }

    /**
     * Limiter key for an anonymous caller: a keyed hash, never the address.
     *
     * @see PseudonymousKey
     */
    public static function anonKey(string $ip, string $secret): string
    {
        return PseudonymousKey::limiter('registration', $ip, $secret);
    }

    /**
     * Localized like every other page (owner, 2026-08-27). This route is reached
     * from a link in an email, so it is the one page a rider arrives at with no
     * referring page to inherit a language from. Carrying the locale in the path
     * means the server writes down at signup time what it already knows, instead
     * of guessing later: `/nl/register` signs a `/nl/verify/email` link, that
     * link routes `_locale=nl`, and the "email confirmed" message and the
     * redirect to `login` both come out Dutch, with no session involved.
     *
     * English keeps the bare `/verify/email`, so links already in inboxes stay
     * valid.
     */
    #[Route([
        'en' => '/verify/email',
        'fr' => '/fr/verify/email',
        'nl' => '/nl/verify/email',
        'de' => '/de/verify/email',
        'es' => '/es/verify/email',
    ], name: 'verify_email')]
    public function verifyUserEmail(
        Request $request,
        UserRepository $userRepository,
    ): Response {
        $id = $request->query->get('id');

        if (null === $id) {
            return $this->redirectToRoute('register');
        }

        $user = $userRepository->find((int) $id);

        if (null === $user) {
            return $this->redirectToRoute('register');
        }

        try {
            $this->emailVerifier->handleEmailConfirmation($request, $user);
        } catch (VerifyEmailExceptionInterface $exception) {
            $this->addFlash('verify_email_error', $exception->getReason());

            return $this->redirectToRoute('register');
        }

        $this->addFlash('success', 'flash.email_verified');

        return $this->redirectToRoute('login');
    }
}
