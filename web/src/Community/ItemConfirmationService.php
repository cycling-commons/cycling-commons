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
 * Confirmations for non-votable items. One stance per rider per item.
 *
 * @see docs/specs/moderation-and-contribution.md §10
 *
 * @api
 */
final class ItemConfirmationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
    ) {
    }

    /**
     * Record or switch a stance. Rejects a stance the item's type does not offer.
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
     * A curator's drawer confirmation verifies the item. Form-sourced answers never do.
     *
     * @see docs/specs/moderation-and-contribution.md §10
     */
    private function verifyIfCurator(Item $item, User $user, ConfirmationStance $stance, ConfirmationSource $source): void
    {
        if (ConfirmationSource::Drawer !== $source
            || ItemState::Unverified !== $item->getState()
            || ConfirmationStance::NotPotable === $stance
            || ConfirmationStance::NotAsDescribed === $stance) {
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
     * Submitter's form answer: stored so the map does not re-ask, never tallied.
     */
    public function recordFromSubmission(Item $item, int $userId, ConfirmationStance $stance): void
    {
        $user = $this->em->find(User::class, $userId);
        if (null === $user) {
            return;
        }

        $this->record($item, $user, $stance, ConfirmationSource::Form);
    }

    /**
     * Public tally plus this user's stance. Form-sourced rows are excluded from counts.
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
