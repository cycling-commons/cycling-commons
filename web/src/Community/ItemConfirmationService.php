<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Community;

use App\Catalog\ConfirmationStance;
use App\Catalog\Entity\Item;
use App\Catalog\Entity\ItemConfirmation;
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
    public function record(Item $item, User $user, ConfirmationStance $stance): void
    {
        $allowed = ItemType::fromParam($item->getLetter())->confirmationStances();
        if (!\in_array($stance, $allowed, true)) {
            throw new \InvalidArgumentException(sprintf('Stance "%s" is not offered for a %s item.', $stance->value, $item->getLetter()));
        }

        $this->em->wrapInTransaction(function () use ($item, $user, $stance): void {
            $existing = $this->em->getRepository(ItemConfirmation::class)
                ->findOneBy(['itemId' => (int) $item->getId(), 'userId' => (int) $user->getId()]);

            if (null !== $existing) {
                $existing->setStance($stance);
            } else {
                $this->em->persist(new ItemConfirmation((int) $item->getId(), (int) $user->getId(), $stance));
            }
        });
    }

    /**
     * Public tally of stances for an item, plus this user's own stance (null for
     * an anonymous viewer). `stances` is keyed only by the stances the item's
     * type actually offers, each defaulting to 0.
     *
     * @return array{stances: array<string, int>, total: int, mine: ?string}
     */
    public function snapshot(Item $item, ?User $user): array
    {
        $offered = ItemType::fromParam($item->getLetter())->confirmationStances();
        $stances = [];
        foreach ($offered as $s) {
            $stances[$s->value] = 0;
        }

        /** @var list<array{stance: string, n: int|string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT stance, COUNT(*) AS n FROM item_confirmation WHERE item_id = :id GROUP BY stance',
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

        $mine = null;
        if (null !== $user) {
            $own = $this->em->getRepository(ItemConfirmation::class)
                ->findOneBy(['itemId' => (int) $item->getId(), 'userId' => (int) $user->getId()]);
            $mine = $own?->getStance()->value;
        }

        return ['stances' => $stances, 'total' => $total, 'mine' => $mine];
    }
}
