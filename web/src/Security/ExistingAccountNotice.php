<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Security;

use App\Controller\VerificationResendController;
use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What a sign-up with an address that already has an account does.
 *
 * The page answers exactly as it does for a new address, so the form never
 * says which addresses have accounts. The inbox learns the rest: an account
 * that never confirmed gets a fresh confirmation link, a confirmed one a note
 * with the way to sign in and to reset the password.
 *
 * Both mails spend the resend page's per-address budget (a quarter hour
 * between mails, three a day), so neither form can be used to flood an inbox,
 * and a refusal looks exactly like a send.
 *
 * @see docs/specs/account-and-auth.md §2
 *
 * @api
 */
final class ExistingAccountNotice
{
    public function __construct(
        private readonly EmailVerifier $emailVerifier,
        private readonly MailerInterface $mailer,
        private readonly TranslatorInterface $translator,
        private readonly RateLimiterFactoryInterface $verifyResendAddressLimiter,
        private readonly RateLimiterFactoryInterface $verifyResendAddressDailyLimiter,
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
    ) {
    }

    public function send(User $user): void
    {
        $key = VerificationResendController::addressKey($user->getEmail(), $this->secret);
        if (!$this->verifyResendAddressLimiter->create($key)->consume()->isAccepted()
            || !$this->verifyResendAddressDailyLimiter->create($key)->consume()->isAccepted()) {
            return;
        }

        if (!$user->isEmailVerified()) {
            $this->emailVerifier->sendConfirmation($user);

            return;
        }

        $this->mailer->send(
            (new TemplatedEmail())
                ->from(new Address('noreply@cyclingcommons.org', 'Cycling Commons'))
                ->to(new Address($user->getEmail()))
                ->subject($this->translator->trans('security.account_exists_email.subject'))
                ->htmlTemplate('emails/account_exists.html.twig'),
        );
    }
}
