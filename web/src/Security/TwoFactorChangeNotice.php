<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Tell the account's own address that its second factor was set up or replaced.
 *
 * Replacing two-factor is how somebody holding a session would keep an
 * account for good, so the owner hears of it at once, at the address the
 * account confirmed and never one a form named. It says what changed and when,
 * and what to do if it was not them: change the password, then write to us so
 * an admin can turn the new factor off.
 *
 * @see docs/specs/account-and-auth.md §4
 *
 * @api
 */
final readonly class TwoFactorChangeNotice
{
    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private ClockInterface $clock,
        #[Autowire('%cc.support.from_email%')]
        private string $fromEmail,
        #[Autowire('%cc.support.public_email%')]
        private string $supportEmail,
    ) {
    }

    /** @param bool $replaced true when the account had two-factor before, false when it is the first time */
    public function send(User $user, bool $replaced): void
    {
        $locale = $user->getLocale();
        $kind = $replaced ? 'moved' : 'on';

        $this->mailer->send(
            (new TemplatedEmail())
                ->from(new Address($this->fromEmail, 'Cycling Commons'))
                ->to(new Address($user->getEmail()))
                ->subject($this->translator->trans('security.twofactor_email.subject_'.$kind, [], null, $locale))
                ->htmlTemplate('emails/two_factor_changed.html.twig')
                ->context([
                    'locale' => $locale,
                    'kind' => $kind,
                    'when' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i'),
                    'support_email' => $this->supportEmail,
                ]),
        );
    }
}
