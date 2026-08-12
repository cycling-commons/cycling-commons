<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Community;

use App\Catalog\ConfirmationSource;
use App\Catalog\ConfirmationStance;
use App\Catalog\Entity\ChangeHistory;
use App\Catalog\Entity\Item;
use App\Catalog\Entity\ItemConfirmation;
use App\Catalog\ItemState;
use App\Catalog\ItemType;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Community confirmations for non-votable items (drinking-water potability, or a
 * plain existence confirmation for a utility). One stance per rider per item,
 * changeable. Tallies are public; recording requires an account.
 *
 * @api Consumed by ItemConfirmationController.
 */
final class ItemConfirmationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
    ) {
    }

    /**
     * Record (or switch) a rider's stance on an item. Rejects a stance the
     * item's type does not offer (e.g. Potable on a bike-service point, or any
     * stance on a votable type).
     */
    public function record(Item $item, User $user, ConfirmationStance $stance, ConfirmationSource $source = ConfirmationSource::Drawer): void
    {
        $allowed = ItemType::fromParam($item->getLetter())->confirmationStances();
        if (!\in_array($stance, $allowed, true)) {
            throw new \InvalidArgumentException(sprintf('Stance "%s" is not offered for a %s item.', $stance->value, $item->getLetter()));
        }

        $this->em->wrapInTransaction(function () use ($item, $user, $stance, $source): void {
            $existing = $this->em->getRepository(ItemConfirmation::class)
                ->findOneBy(['itemId' => (int) $item->getId(), 'userId' => (int) $user->getId()]);

            if (null !== $existing) {
                $existing->setStance($stance);
                // A drawer answer promotes a form-sourced row (the submitter has
                // now confirmed as a rider); a form answer never demotes a real
                // confirmation back out of the tally.
                if (ConfirmationSource::Drawer === $source) {
                    $existing->setSource($source);
                }
            } else {
                $this->em->persist(new ItemConfirmation((int) $item->getId(), (int) $user->getId(), $stance, $source));
            }

            $this->verifyIfCurator($item, $user, $stance, $source);
        });
    }

    /**
     * A curator's own confirmation verifies the item outright.
     *
     * The question a confirmation answers is "is this real, and is it right" —
     * and the honest answer for most things is *we cannot know until several
     * unrelated people say so*. A photo can be generated; a place can be
     * invented. Repetition by strangers is the only check this project has, and
     * that is why it exists.
     *
     * But it is the wrong instrument for a castle (owner 2026-08-12). Some
     * things a curator can settle by looking — a listed monument, a station, a
     * public fountain in a town square — and making them wait for three riders
     * to pass by is ceremony, not verification. So a curator standing behind an
     * entry IS the verification, once, and it is recorded as an act of theirs in
     * the item's history rather than happening quietly.
     *
     * Deliberately NOT a new moderation mechanic: it is the same confirm control
     * every rider uses, weighted by who pressed it. Nothing queues, nothing is
     * approved, and a curator who is wrong is corrected the way anything else is.
     * A `form`-sourced answer never verifies — the submitter is not a witness to
     * their own submission.
     */
    private function verifyIfCurator(Item $item, User $user, ConfirmationStance $stance, ConfirmationSource $source): void
    {
        if (ConfirmationSource::Drawer !== $source
            || ItemState::Unverified !== $item->getState()
            || ConfirmationStance::NotPotable === $stance) {   // a warning is not a vouching
            return;
        }
        $roles = $user->getRoles();
        if (!\in_array('ROLE_CURATOR', $roles, true) && !\in_array('ROLE_ADMIN', $roles, true)) {
            return;
        }

        $item->setState(ItemState::Verified);
        $this->em->persist((new ChangeHistory())
            ->setItemId((int) $item->getId())
            ->setField('state')
            ->setOldValue(ItemState::Unverified->value)
            ->setNewValue(ItemState::Verified->value)
            ->setChangedBy((int) $user->getId()));
    }

    /**
     * The submitter's own answer to the same question, taken from an approved
     * submission's payload — recorded so the map never asks them again, and
     * never counted (ConfirmationSource::Form).
     *
     * Silent no-op when the submission asserted nothing: the potability field
     * offers "Unsigned — use judgement", which is not a claim either way, and
     * a submitter who left it alone has answered nothing.
     */
    public function recordFromSubmission(Item $item, int $userId, ConfirmationStance $stance): void
    {
        $user = $this->em->find(User::class, $userId);
        if (null === $user) {
            return;   // account gone between submitting and approval
        }

        $this->record($item, $user, $stance, ConfirmationSource::Form);
    }

    /**
     * Public tally of stances for an item, plus this user's own stance (null for
     * an anonymous viewer). `stances` is keyed only by the stances the item's
     * type actually offers, each defaulting to 0.
     *
     * @return array{stances: array<string, int>, total: int, mine: ?string, mineSource: ?string}
     */
    public function snapshot(Item $item, ?User $user): array
    {
        $offered = ItemType::fromParam($item->getLetter())->confirmationStances();
        $stances = [];
        foreach ($offered as $s) {
            $stances[$s->value] = 0;
        }

        // Form-sourced rows are the submitter's own answer on the improve form.
        // They are kept (so the map never re-asks the person who added the
        // place) but never tallied: "2 riders confirmed" must mean two riders
        // confirmed it, not one rider and the person making the claim
        // (ConfirmationSource).
        /** @var list<array{stance: string, n: int|string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            "SELECT stance, COUNT(*) AS n FROM item_confirmation WHERE item_id = :id AND source <> 'form' GROUP BY stance",
            ['id' => (int) $item->getId()],
        );
        $total = 0;
        foreach ($rows as $row) {
            $count = (int) $row['n'];
            $total += $count;
            if (\array_key_exists($row['stance'], $stances)) {
                $stances[$row['stance']] = $count;
            }
        }

        // `mine` ignores the source: the reader answered, whichever way they
        // answered, and the drawer must not put the question to them again.
        // `mineSource` lets it say WHERE they answered, so a submitter reading
        // their own form answer back is not left wondering when they confirmed
        // a place they only just added.
        $mine = null;
        $mineSource = null;
        if (null !== $user) {
            $own = $this->em->getRepository(ItemConfirmation::class)
                ->findOneBy(['itemId' => (int) $item->getId(), 'userId' => (int) $user->getId()]);
            $mine = $own?->getStance()->value;
            $mineSource = $own?->getSource()->value;
        }

        return ['stances' => $stances, 'total' => $total, 'mine' => $mine, 'mineSource' => $mineSource];
    }
}
