<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Account;

use App\Entity\User;
use App\Media\Entity\ConsentRecord;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Turning the release list on and off, and the link that turns it off without
 * a password.
 *
 * Two halves that have to agree:
 *
 * - **Opting in writes a consent record.** The boolean on the account says what
 *   is true now; the record says when it was agreed and to which wording. Same
 *   split as the photo licence, and the reason is the same: a flag alone cannot
 *   answer "what did they actually agree to", which is the question that gets
 *   asked when somebody complains.
 * - **Opting out needs no proof.** Withdrawing consent must be at least as easy
 *   as giving it, so the flag flips and nothing is demanded in return. The
 *   consent record stays: it is evidence of a thing that did happen, not a
 *   claim that it still holds.
 *
 * The unsubscribe link is signed rather than stored. A stored token is another
 * row to expire, revoke and leak; an HMAC over the account's uuid needs none of
 * that, cannot be guessed, and stays valid exactly as long as the account does.
 * It is the account **uuid** and not the id, so the link says nothing about how
 * many riders there are.
 *
 * @see docs/specs/roadmap-and-changelog.md §4
 *
 * @api
 */
final class UpdatesSubscription
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
    ) {
    }

    /**
     * Record a change of mind, if it is one.
     *
     * Called with the value the form already wrote onto the account, so it
     * compares against what was there before rather than guessing.
     */
    public function applied(User $user, bool $wasOptedIn): void
    {
        if ($user->isUpdatesOptIn() === $wasOptedIn) {
            return;
        }

        if ($user->isUpdatesOptIn()) {
            $this->em->persist(new ConsentRecord(
                Uuid::v4(),
                (int) $user->getId(),
                UpdatesConsent::KIND,
                UpdatesConsent::VERSION,
                UpdatesConsent::hash(),
            ));
        }
    }

    /**
     * The `?u=&t=` pair for a one-click unsubscribe link.
     *
     * @return array{u: string, t: string}
     */
    public function linkParameters(User $user): array
    {
        $uuid = (string) $user->getUuid();

        return ['u' => $uuid, 't' => $this->token($uuid)];
    }

    /**
     * Turn the list off for whoever this link belongs to.
     *
     * Returns false for a bad signature, an unknown account, or an account that
     * was already off, so the caller can answer the same way in every case: a
     * page that says "you will not get these" is the truthful response to all
     * four, and distinguishing them would turn the link into an oracle for
     * whether a given uuid exists.
     */
    public function unsubscribe(string $uuid, string $token): bool
    {
        if (!hash_equals($this->token($uuid), $token)) {
            return false;
        }

        $user = $this->em->getRepository(User::class)->findOneBy(['uuid' => $uuid]);
        if (!$user instanceof User || !$user->isUpdatesOptIn()) {
            return false;
        }

        $user->setUpdatesOptIn(false);
        $this->em->flush();

        return true;
    }

    private function token(string $uuid): string
    {
        return substr(hash_hmac('sha256', 'updates-unsubscribe|'.$uuid, $this->secret), 0, 32);
    }
}
