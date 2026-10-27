<?php

// SPDX-License-Identifier: AGPL-3.0-only

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
use App\Settings\SettingsProviderInterface;
use App\Settings\SettingsRegistry;
use Doctrine\DBAL\ArrayParameterType;
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
        private readonly SettingsProviderInterface $settings,
    ) {
    }

    /**
     * The stances this ROW offers, which is the type's set with one exception:
     * a water row whose potability an authority has settled (a tap in the
     * national drinking-water register) is not asked whether it is drinkable.
     * The register is the answer; what a rider can still add is "I stood
     * there and it is here". Owner 2026-09-06: "should not be a question, as
     * this is a drinking water provider".
     *
     * @return list<ConfirmationStance>
     */
    public static function offeredFor(Item $item): array
    {
        $type = ItemType::fromParam($item->getLetter());
        if (ItemType::WaterFood === $type && null !== $item->getProvider()
            && str_starts_with((string) ($item->getAttributes()['potable'] ?? ''), 'Yes')) {
            return [ConfirmationStance::Exists];
        }

        return $type->confirmationStances();
    }

    /** The question the drawer asks, from the offered stances. */
    public static function stanceKindFor(Item $item): string
    {
        $offered = self::offeredFor($item);
        if (\in_array(ConfirmationStance::Potable, $offered, true)) {
            return 'potability';
        }
        if (\in_array(ConfirmationStance::NotAsDescribed, $offered, true)) {
            return 'accuracy';
        }

        return 'existence';
    }

    /**
     * Record or switch a stance. Rejects a stance the item's type does not offer.
     */
    public function record(Item $item, User $user, ConfirmationStance $stance, ConfirmationSource $source = ConfirmationSource::Drawer): void
    {
        $allowed = self::offeredFor($item);
        if (!\in_array($stance, $allowed, true)) {
            throw new \InvalidArgumentException(sprintf('Stance "%s" is not offered for a %s item.', $stance->value, $item->getLetter()));
        }

        $byCurator = self::isCurator($user);

        $this->em->wrapInTransaction(function () use ($item, $user, $stance, $source, $byCurator): void {
            $existing = $this->em->getRepository(ItemConfirmation::class)
                ->findOneBy(['itemId' => (int) $item->getId(), 'userId' => (int) $user->getId()]);

            if (null !== $existing) {
                $existing->setStance($stance);
                $existing->setByCurator($byCurator);
                if (ConfirmationSource::Drawer === $source) {
                    $existing->setSource($source);
                }
            } else {
                $this->em->persist(new ItemConfirmation((int) $item->getId(), (int) $user->getId(), $stance, $source, $byCurator));
            }

            // The tally below is a DBAL count, so this rider's own row has to
            // be in the table before it is asked how many there are.
            $this->em->flush();
            $this->verifyIfEarned($item, $user, $stance, $source);
        });
    }

    /**
     * Promote Unverified to Verified when this confirmation earns it.
     *
     * Two ways in, one destination. A curator's own drawer confirmation
     * settles it outright: three riders should not be needed to agree that a
     * castle is a castle. Otherwise it takes
     * `map.item_verify_threshold` independent riders, which is the same
     * instrument the routes queue uses and the only real check this project
     * has, since a photo can be generated and a place invented.
     *
     * The rule is the SAME whatever published the row. An OpenStreetMap node,
     * a national register entry and a rider's own pin all start Unverified and
     * all leave it the same way: somebody stood there (owner 2026-09-09). One
     * definition of verified, so the pin and the record can never disagree.
     *
     * Form-sourced answers never count: a submitter is not a witness to their
     * own submission.
     *
     * @see docs/specs/moderation-and-contribution.md §10
     */
    private function verifyIfEarned(Item $item, User $user, ConfirmationStance $stance, ConfirmationSource $source): void
    {
        if (ConfirmationSource::Drawer !== $source
            || ItemState::Unverified !== $item->getState()
            || !\in_array($stance, ConfirmationStance::vouching(), true)) {
            return;
        }

        if (!self::isCurator($user) && $this->vouchingTally($item) < $this->settings->get(SettingsRegistry::MAP_ITEM_VERIFY_THRESHOLD)) {
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
     * Verify a place that has just gone live when its confirmations already earn it: a curator's, or enough riders'.
     * A confirmation given while the place waited counts from its approval.
     */
    public function verifyIfVouched(Item $item): void
    {
        if (ItemState::Unverified !== $item->getState()) {
            return;
        }
        $by = $this->db->fetchOne(
            'SELECT user_id FROM item_confirmation
              WHERE item_id = :id AND source <> :form AND stance IN (:stances)
              ORDER BY by_curator DESC, id LIMIT 1',
            ['id' => (int) $item->getId(), 'form' => ConfirmationSource::Form->value, 'stances' => array_map(static fn (ConfirmationStance $s): string => $s->value, ConfirmationStance::vouching())],
            ['stances' => ArrayParameterType::STRING],
        );
        if (false === $by) {
            return;
        }
        $user = $this->em->find(User::class, (int) $by);
        if (null === $user) {
            return;
        }
        if (!self::isCurator($user) && $this->vouchingTally($item) < $this->settings->get(SettingsRegistry::MAP_ITEM_VERIFY_THRESHOLD)) {
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
     * The confirmations a waiting place got, counted for the place it was closed into (a new road added to the road
     * it lies on, edit-items/A-road-surface.md): each vouching confirmation, form answers excluded, by somebody who
     * has none on that place yet, and the place verified when they earn it.
     */
    public function carryVouches(Item $from, Item $into): void
    {
        $this->db->executeStatement(
            'INSERT INTO item_confirmation (item_id, user_id, stance, created_at, updated_at, source, by_curator)
             SELECT :into, f.user_id, f.stance, f.created_at, NOW(), f.source, f.by_curator
               FROM item_confirmation f
              WHERE f.item_id = :from AND f.user_id IS NOT NULL AND f.source <> :form AND f.stance IN (:stances)
                AND NOT EXISTS (SELECT 1 FROM item_confirmation i WHERE i.item_id = :into AND i.user_id = f.user_id)',
            ['into' => (int) $into->getId(), 'from' => (int) $from->getId(), 'form' => ConfirmationSource::Form->value, 'stances' => array_map(static fn (ConfirmationStance $s): string => $s->value, ConfirmationStance::vouching())],
            ['stances' => ArrayParameterType::STRING],
        );
        $this->verifyIfVouched($into);
    }

    /**
     * Which of these places this rider vouches for, form answers excluded: id => true.
     *
     * @param list<int> $itemIds
     *
     * @return array<int, true>
     */
    public function vouchedAmong(int $userId, array $itemIds): array
    {
        if ([] === $itemIds) {
            return [];
        }
        $ids = $this->db->fetchFirstColumn(
            'SELECT item_id FROM item_confirmation
              WHERE user_id = :u AND item_id IN (:ids) AND source <> :form AND stance IN (:stances)',
            ['u' => $userId, 'ids' => $itemIds, 'form' => ConfirmationSource::Form->value, 'stances' => array_map(static fn (ConfirmationStance $s): string => $s->value, ConfirmationStance::vouching())],
            ['ids' => ArrayParameterType::INTEGER, 'stances' => ArrayParameterType::STRING],
        );

        return array_fill_keys(array_map(intval(...), $ids), true);
    }

    /** Curator or admin: the two roles whose single word settles the verified state (§10.1). */
    private static function isCurator(User $user): bool
    {
        $roles = $user->getRoles();

        return \in_array('ROLE_CURATOR', $roles, true) || \in_array('ROLE_ADMIN', $roles, true);
    }

    /**
     * Riders vouching for this item right now, form answers excluded.
     *
     * One row per rider is a database constraint, so a count of rows is a
     * count of people and the same rider tapping twice can never add up.
     */
    private function vouchingTally(Item $item): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM item_confirmation
              WHERE item_id = :id AND source <> :form AND stance IN (:stances)',
            [
                'id' => (int) $item->getId(),
                'form' => ConfirmationSource::Form->value,
                'stances' => array_map(static fn (ConfirmationStance $s): string => $s->value, ConfirmationStance::vouching()),
            ],
            ['stances' => ArrayParameterType::STRING],
        );
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
     * @return array{stances: array<string, int>, total: int, byCurator: bool, mine: ?string, mineSource: ?string}
     */
    public function snapshot(Item $item, ?User $user): array
    {
        $offered = self::offeredFor($item);
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

        // A curator's word is the stronger witness, so the drawer names it
        // instead of counting heads (owner 2026-09-10). Read from the column,
        // not from the roles a confirmer holds today: the record says who was
        // standing there, and a later promotion or demotion cannot rewrite it.
        $byCurator = (bool) $this->db->fetchOne(
            "SELECT EXISTS (SELECT 1 FROM item_confirmation
                             WHERE item_id = :id AND source <> 'form' AND by_curator = true AND stance IN (:stances))",
            ['id' => (int) $item->getId(), 'stances' => array_map(
                static fn (ConfirmationStance $s): string => $s->value,
                ConfirmationStance::vouching(),
            )],
            ['stances' => ArrayParameterType::STRING],
        );

        $mine = null;
        $mineSource = null;
        if (null !== $user) {
            $own = $this->em->getRepository(ItemConfirmation::class)
                ->findOneBy(['itemId' => (int) $item->getId(), 'userId' => (int) $user->getId()]);
            $mine = $own?->getStance()->value;
            $mineSource = $own?->getSource()->value;
        }

        return ['stances' => $stances, 'total' => $total, 'byCurator' => $byCurator, 'mine' => $mine, 'mineSource' => $mineSource];
    }
}
