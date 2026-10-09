<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Account;

use App\Entity\User;
use App\Service\UserDeletionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Warn, warn, warn, then close.
 *
 * `/privacy` promised, in five languages, that we do not delete an account just
 * because nobody has signed into it, and that **if we ever start we write first
 * and give time**. This is that promise kept: three emails over a year
 * ({@see DormancyLadder}), and only then the account goes.
 *
 * Two rules the implementation exists to hold:
 *
 * - **Never delete without having sent all three notices.** Not "24 months have
 *   passed", but "24 months have passed *and* they were told, three times".
 *   Because each notice only fires inside its own month-wide window, an account
 *   that was already long dormant when this shipped can never earn them, and so
 *   can never be deleted by it. Switching this on cannot clear a backlog.
 * - **Deleting is the same deletion a rider asks for.** It goes through
 *   {@see UserDeletionService::purge()}, so contributions are anonymised rather
 *   than cascaded and a photo licence survives exactly as it does today. A
 *   second deletion path would have drifted from the first within a year.
 *
 * Each account is erased in its own transaction
 * ({@see UserDeletionService::eraseInBatch()}): one whose deletion fails stays
 * whole, is logged by id and counted as failed, and the sweep goes on. A
 * notice is recorded right after its email goes, so a later failure cannot
 * lose the record and send the same notice again tomorrow.
 *
 * @see docs/specs/account-and-auth.md §6.5
 *
 * @api
 */
final class DormancySweep
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserDeletionService $deletions,
        private readonly MailerInterface $mailer,
    ) {
    }

    /**
     * @return array{notified: array<string, int>, deleted: int, failed: int, considered: int}
     */
    public function run(\DateTimeImmutable $now, bool $dryRun = false): array
    {
        $notified = array_fill_keys(array_keys(DormancyLadder::NOTICES), 0);
        $deleted = 0;
        $failed = 0;
        $considered = 0;

        $ids = array_map(static fn (User $user): int => (int) $user->getId(), $this->candidates($now));
        foreach ($ids as $id) {
            // By id: a failed deletion before this one detached every loaded account.
            $user = $this->em->find(User::class, $id);
            if (!$user instanceof User) {
                continue;
            }
            ++$considered;
            $idle = $this->monthsIdle($user, $now);
            $sent = $user->dormancyNoticesSent();

            if (DormancyLadder::isDeletable($idle, $sent)) {
                if (!$dryRun && !$this->deletions->eraseInBatch($user)) {
                    ++$failed;
                    continue;
                }
                ++$deleted;
                continue;
            }

            $due = DormancyLadder::noticeDue($idle, $sent);
            if (null === $due) {
                continue;
            }

            if (!$dryRun) {
                $this->warn($user, $due, $idle);
                $user->recordDormancyNotice($due, $now);
                $this->em->flush();
            }
            ++$notified[$due];
        }

        return ['notified' => $notified, 'deleted' => $deleted, 'failed' => $failed, 'considered' => $considered];
    }

    /**
     * Accounts idle long enough to be worth looking at.
     *
     * Filtered in SQL rather than in PHP: the sweep runs against every account
     * there has ever been, and all but a handful are active.
     *
     * @return list<User>
     */
    private function candidates(\DateTimeImmutable $now): array
    {
        $firstRung = min(DormancyLadder::NOTICES);

        /** @var list<User> $idle */
        $idle = $this->em->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.lastLoginAt IS NOT NULL')
            ->andWhere('u.lastLoginAt <= :cutoff')
            // A suspended account could not sign in: its idle time counts from
            // the end of the suspension, so one still running is never a
            // candidate (account-and-auth.md §6.8).
            ->andWhere('u.suspendedUntil IS NULL OR u.suspendedUntil <= :cutoff')
            // A rider already on their way out has their own clock running,
            // for as long as their deletion code works. A code nobody typed
            // is an abandoned request and exempts nobody.
            ->andWhere('u.deletionRequestedAt IS NULL OR u.deletionRequestedAt <= :codeExpired')
            ->setParameter('cutoff', $now->modify(sprintf('-%d months', $firstRung)))
            ->setParameter('codeExpired', $now->modify(sprintf('-%d minutes', UserDeletionService::CODE_MINUTES)))
            ->orderBy('u.id')
            ->getQuery()
            ->getResult();

        // An administrator is never closed for silence. There may be exactly
        // one, and a sweep that removes the last operator of the site is a
        // lockout, not housekeeping: nobody would be left to restore anything.
        // Filtered here rather than in DQL because roles live in a JSON column
        // that Postgres will not LIKE against, and the list is short.
        return array_values(array_filter(
            $idle,
            static fn (User $user): bool => !\in_array('ROLE_ADMIN', $user->getRoles(), true),
        ));
    }

    private function monthsIdle(User $user, \DateTimeImmutable $now): int
    {
        $last = self::idleSince($user);
        if (null === $last) {
            return 0;
        }

        $diff = $last->diff($now);

        return ($diff->y * 12) + $diff->m;
    }

    /**
     * When the account was last in its holder's hands: the last sign-in, or
     * the end of a suspension after it, since a suspended account cannot be
     * signed into.
     */
    private static function idleSince(User $user): ?\DateTimeImmutable
    {
        $last = $user->getLastLoginAt();
        $suspended = $user->getSuspendedUntil();
        if (null === $last || null === $suspended) {
            return $last;
        }

        return max($last, $suspended);
    }

    /**
     * One email per rung, each naming the date the account would close.
     *
     * The date is the point. "Your account is inactive" is a notification;
     * "it will close on 4 March unless you sign in" is a thing somebody can act
     * on.
     */
    private function warn(User $user, string $notice, int $idle): void
    {
        $last = self::idleSince($user);
        $closesOn = $last?->modify(sprintf('+%d months', DormancyLadder::DELETE_AFTER_MONTHS));

        $email = (new TemplatedEmail())
            ->from(new Address('noreply@cyclingcommons.org', 'Cycling Commons'))
            ->to(new Address($user->getEmail(), $user->getDisplayName()))
            ->subject('Your Cycling Commons account')
            ->htmlTemplate('emails/dormancy_notice.html.twig')
            ->context([
                'user' => $user,
                'notice' => $notice,
                'monthsIdle' => $idle,
                'closesOn' => $closesOn,
                'isFinal' => 'm23_final' === $notice,
            ]);

        $this->mailer->send($email);
    }
}
