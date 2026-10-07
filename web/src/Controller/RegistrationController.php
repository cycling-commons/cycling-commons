<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Account\DisplayNameCheck;
use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Repository\UserRepository;
use App\Security\EmailVerifier;
use App\Security\ExistingAccountNotice;
use App\Security\PseudonymousKey;
use App\Security\SignupGuard;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
    public function __construct(
        private readonly EmailVerifier $emailVerifier,
        private readonly SignupGuard $guard,
        private readonly UserRepository $users,
        private readonly ExistingAccountNotice $existingAccount,
        private readonly DisplayNameCheck $nameCheck,
    ) {
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

        // A new account signs up under the notice as it stands now.

        $user->setPrivacyVersionSeen(\App\Legal\PrivacyNoticeVersions::CURRENT);
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if (!$form->isSubmitted()) {
            return $this->page($form);
        }

        // Bots signed strangers up to mail them (2026-09-28). The free checks
        // run first, so a flood of them spends nobody's rate-limit budget.
        $now = new \DateTimeImmutable();
        $refusal = $this->guard->refusal($request, $now);
        if (null !== $refusal) {
            return $this->refuse($form, $refusal, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!$form->isValid()) {
            return $this->page($form);
        }

        $refusal = $this->guard->proofRefusal($request, $now);
        if (null !== $refusal) {
            return $this->refuse($form, $refusal, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Quota BEFORE anything is written or sent. One unauthenticated POST
        // creates a row and mails a confirmation link to whatever address was
        // typed (security scan 2026-08-25). A visible error, not a silent
        // redirect: it is about the sender's connection, never the address.
        if (!$registrationLimiter->create(self::anonKey($request->getClientIp() ?? 'unknown', $secret))->consume()->isAccepted()) {
            return $this->refuse($form, 'security.register.error_rate_limited', Response::HTTP_TOO_MANY_REQUESTS);
        }

        if (!$this->guard->domainResolves($user->getEmail())) {
            $form->get('email')->addError(new FormError('security.guard.email_domain'));

            return $this->page($form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var string $plainPassword */
        $plainPassword = $form->get('plainPassword')->getData();

        // Hashed before the lookup, so a taken address costs the same time as
        // a new one and the answer's timing says nothing either.
        $user->setPassword(
            $userPasswordHasher->hashPassword($user, $plainPassword)
        );

        // The display-name hint for a visitor without JavaScript, asked
        // before the address is looked up and from the name alone, so a taken
        // address and a new one get the same hint and the same page.
        $nameInUse = $this->nameCheck->inUse($user->getDisplayName());

        // A taken address gets the same page as a new one; the inbox learns
        // the rest (ExistingAccountNotice). The form never says which
        // addresses have accounts.
        $existing = $this->users->findByEmail($user->getEmail());
        if (null !== $existing) {
            $this->existingAccount->send($existing);

            return $this->checkEmail($nameInUse);
        }

        $user->setRoles(['ROLE_USER']);
        $user->setEmailVerified(false);
        $user->confirmAge($now);

        try {
            $entityManager->persist($user);
            $entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Two sign-ups for one address at once: the other one won, and
            // this one answers like any taken address, never a 500.
            return $this->checkEmail($nameInUse);
        }

        $this->emailVerifier->sendConfirmation($user);

        return $this->checkEmail($nameInUse);
    }

    private function checkEmail(bool $nameInUse): Response
    {
        return $this->render('security/check_email.html.twig', [
            'page_title' => 'meta.register_check_email_title',
            'page_description' => 'meta.register_check_email_description',
            'name_in_use' => $nameInUse,
        ]);
    }

    private function page(FormInterface $form, ?int $status = null): Response
    {
        return $this->render('security/register.html.twig', [
            'registrationForm' => $form,
            'page_title' => 'meta.register_title',
            'page_description' => 'meta.register_description',
        ] + $this->guard->context(new \DateTimeImmutable()), null === $status ? null : new Response('', $status));
    }

    /**
     * A form-level error: these keys name no field, and the page shows
     * form-level errors (partials/_form_errors.html.twig).
     */
    private function refuse(FormInterface $form, string $key, int $status): Response
    {
        $form->addError(new FormError($key));

        return $this->page($form, $status);
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
            // Most often an expired link: the resend page is the way out.
            $this->addFlash('verify_email_error', $exception->getReason());

            return $this->redirectToRoute('verify_resend');
        }

        $this->addFlash('success', 'flash.email_verified');

        return $this->redirectToRoute('login');
    }
}
