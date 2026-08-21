<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Account deletion. Self-service and admin share this seam; contributions are anonymised, never cascaded.
 *
 * @see docs/specs/account-and-auth.md §6.3, §10
 *
 * @api
 */
final class UserDeletionService
{
    /** @param iterable<UserDeletionHookInterface> $hooks */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MailerInterface $mailer,
        private readonly iterable $hooks,
    ) {
    }

    public function requestDeletion(User $user): void
    {
        $code = strtoupper(bin2hex(random_bytes(4)));
        $user->setDeletionCode($code);
        $user->setDeletionRequestedAt(new \DateTimeImmutable());
        $this->em->flush();

        $email = (new TemplatedEmail())
            ->from(new Address('noreply@cyclingcommons.org', 'Cycling Commons'))
            ->to(new Address($user->getEmail(), $user->getDisplayName()))
            ->subject('Your Cycling Commons account deletion code')
            ->htmlTemplate('emails/account_deletion.html.twig')
            ->context(['user' => $user, 'code' => $code]);
        $this->mailer->send($email);
    }

    public function confirmDeletion(User $user, string $code): bool
    {
        $storedCode = $user->getDeletionCode();
        $requestedAt = $user->getDeletionRequestedAt();

        if (null === $storedCode || null === $requestedAt) {
            return false;
        }

        $expiry = $requestedAt->modify('+1 hour');
        if (new \DateTimeImmutable() > $expiry) {
            return false;
        }

        if (!hash_equals($storedCode, strtoupper($code))) {
            return false;
        }

        $this->purge($user);
        $this->em->flush();

        return true;
    }

    /**
     * Hooks then remove. Caller owns flush/transaction.
     *
     * @api
     */
    public function purge(User $user): void
    {
        foreach ($this->hooks as $hook) {
            $hook->preDelete($user);
        }

        $this->em->remove($user);
    }
}
