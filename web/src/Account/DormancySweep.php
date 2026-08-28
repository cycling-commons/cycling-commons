<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

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
     * @return array{notified: array<string, int>, deleted: int, considered: int}
     */
    public function run(\DateTimeImmutable $now, bool $dryRun = false): array
    {
        $notified = array_fill_keys(array_keys(DormancyLadder::NOTICES), 0);
        $deleted = 0;
        $considered = 0;

        foreach ($this->candidates($now) as $user) {
            ++$considered;
            $idle = $this->monthsIdle($user, $now);
            $sent = $user->dormancyNoticesSent();

            if (DormancyLadder::isDeletable($idle, $sent)) {
                if (!$dryRun) {
                    $this->deletions->purge($user);
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
            }
            ++$notified[$due];
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        return ['notified' => $notified, 'deleted' => $deleted, 'considered' => $considered];
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

        return $this->em->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.lastLoginAt IS NOT NULL')
            ->andWhere('u.lastLoginAt <= :cutoff')
            // A rider already on their way out has their own clock running.
            ->andWhere('u.deletionRequestedAt IS NULL')
            ->setParameter('cutoff', $now->modify(sprintf('-%d months', $firstRung)))
            ->getQuery()
            ->getResult();
    }

    private function monthsIdle(User $user, \DateTimeImmutable $now): int
    {
        $last = $user->getLastLoginAt();
        if (null === $last) {
            return 0;
        }

        $diff = $last->diff($now);

        return ($diff->y * 12) + $diff->m;
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
        $last = $user->getLastLoginAt();
        $closesOn = $last?->modify(sprintf('+%d months', DormancyLadder::DELETE_AFTER_MONTHS));

        $email = (new TemplatedEmail())
            ->from(new Address('noreply@cyclingcommons.org', 'Cycling Commons'))
            ->to(new Address((string) $user->getEmail(), (string) $user->getDisplayName()))
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
