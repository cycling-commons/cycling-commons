<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\VerificationResendFormType;
use App\Repository\UserRepository;
use App\Security\EmailVerifier;
use App\Security\PseudonymousKey;
use App\Security\SignupGuard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A new confirmation link for an account that never confirmed.
 *
 * The way back for a rider whose link expired. Every outcome renders the same
 * page, so the form says nothing about which addresses have accounts; only the
 * per-connection limit is visible, and it is about the sender, not the
 * address. Signing up again with the address ends the same way, and spends the
 * same per-address budget ({@see \App\Security\ExistingAccountNotice}).
 *
 * @see docs/specs/account-and-auth.md §2
 *
 * @api
 */
final class VerificationResendController extends AbstractController
{
    public function __construct(
        private readonly SignupGuard $guard,
        private readonly EmailVerifier $emailVerifier,
        private readonly UserRepository $users,
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
    ) {
    }

    #[Route([
        'en' => '/verify/resend',
        'fr' => '/fr/verify/resend',
        'nl' => '/nl/verify/resend',
        'de' => '/de/verify/resend',
        'es' => '/es/verify/resend',
    ], name: 'verify_resend')]
    public function resend(
        Request $request,
        RateLimiterFactoryInterface $verifyResendLimiter,
        RateLimiterFactoryInterface $verifyResendAddressLimiter,
        RateLimiterFactoryInterface $verifyResendAddressDailyLimiter,
    ): Response {
        $form = $this->createForm(VerificationResendFormType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted()) {
            return $this->page($form);
        }

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

        if (!$verifyResendLimiter->create(self::ipKey($request->getClientIp() ?? 'unknown', $this->secret))->consume()->isAccepted()) {
            return $this->refuse($form, 'security.resend.error_rate_limited', Response::HTTP_TOO_MANY_REQUESTS);
        }

        /** @var string $email */
        $email = $form->get('email')->getData();
        $user = $this->users->findByEmail($email);

        // The per-address budget is spent only for an account that would get
        // a mail, and a refusal looks exactly like a send.
        if ($user instanceof User && !$user->isEmailVerified()) {
            $key = self::addressKey($email, $this->secret);
            if ($verifyResendAddressLimiter->create($key)->consume()->isAccepted()
                && $verifyResendAddressDailyLimiter->create($key)->consume()->isAccepted()) {
                $this->emailVerifier->sendConfirmation($user);
            }
        }

        return $this->render('security/verify_resend.html.twig', [
            'sent' => true,
            'page_title' => 'meta.verify_resend_title',
            'page_description' => 'meta.verify_resend_description',
        ]);
    }

    /** @see PseudonymousKey */
    public static function ipKey(string $ip, string $secret): string
    {
        return PseudonymousKey::limiter('verify-resend', $ip, $secret);
    }

    /** Keyed on the lower-cased address, so `Rider@` cannot reset `rider@`'s budget. */
    public static function addressKey(string $email, string $secret): string
    {
        return PseudonymousKey::limiter('verify-resend-address', User::normalizeEmail($email), $secret);
    }

    private function page(FormInterface $form, ?int $status = null): Response
    {
        return $this->render('security/verify_resend.html.twig', [
            'sent' => false,
            'resendForm' => $form,
            'page_title' => 'meta.verify_resend_title',
            'page_description' => 'meta.verify_resend_description',
        ] + $this->guard->context(new \DateTimeImmutable()), null === $status ? null : new Response('', $status));
    }

    private function refuse(FormInterface $form, string $key, int $status): Response
    {
        $form->addError(new FormError($key));

        return $this->page($form, $status);
    }
}
