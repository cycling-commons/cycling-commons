<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Service;

use App\Entity\User;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Account deletion. Self-service and admin share this seam; contributions are anonymised, never cascaded.
 *
 * An account is erased whole or not at all: the hooks' statements, the
 * removal and the flush run in one transaction, so a failure anywhere leaves
 * the account and every row that names it as they were.
 *
 * @see docs/specs/account-and-auth.md §6.3, §10
 *
 * @api
 */
final class UserDeletionService
{
    /** How long the emailed deletion code works. After that the request is abandoned. */
    public const int CODE_MINUTES = 60;

    /** @param iterable<UserDeletionHookInterface> $hooks */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MailerInterface $mailer,
        private readonly iterable $hooks,
        private readonly LoggerInterface $logger,
        private readonly ManagerRegistry $doctrine,
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

        $expiry = $requestedAt->modify('+'.self::CODE_MINUTES.' minutes');
        if (new \DateTimeImmutable() > $expiry) {
            return false;
        }

        if (!hash_equals($storedCode, strtoupper($code))) {
            return false;
        }

        $this->erase($user);

        return true;
    }

    /**
     * Hooks, removal and flush in one transaction. Inside a caller's
     * transaction it nests as a savepoint.
     *
     * @api
     */
    public function erase(User $user): void
    {
        $this->em->getConnection()->transactional(function () use ($user): void {
            $this->purge($user);
            $this->em->flush();
        });
    }

    /**
     * Erase one account of a batch (a sweep, an operator command). A failure
     * rolls back that account only, is logged by account id, never the
     * address, and leaves the EntityManager usable for the next account: it
     * is cleared, or reset when a failed flush closed it. Either way every
     * entity loaded before is detached, so the caller loads each account by
     * id. Call it outside any transaction.
     *
     * @return bool whether the account was erased
     *
     * @api
     */
    public function eraseInBatch(User $user): bool
    {
        $id = (int) $user->getId();
        try {
            $this->erase($user);

            return true;
        } catch (\Throwable $e) {
            // The class and SQLSTATE only: a driver message can quote the address.
            $this->logger->error('Account erasure failed and was rolled back; the account is untouched.', [
                'user_id' => $id,
                'exception_class' => $e::class,
                'sqlstate' => $e instanceof DriverException ? $e->getSQLState() : null,
            ]);
            if ($this->em->isOpen()) {
                $this->em->clear();
            } else {
                $this->doctrine->resetManager();
            }

            return false;
        }
    }

    /**
     * Hooks then remove. Caller owns flush/transaction: erase() is both.
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
