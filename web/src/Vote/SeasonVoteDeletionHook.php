<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Vote;

use App\Catalog\BikeType;
use App\Catalog\ItemType;
use App\Entity\User;
use App\Service\UserDeletionHookInterface;
use Doctrine\DBAL\Connection;

/**
 * Account deletion: a rider's votes go with the account; the closed seasons
 * they voted in keep their totals.
 *
 * Every closed list the rider voted in is stored first, so its result still
 * counts them and names nobody; then every vote row of the rider is deleted.
 * The open round loses the vote. The owner's call for the ballot,
 * 2026-07-30: past rounds keep the vote.
 *
 * @see docs/specs/route-domain.md §8d
 * @see docs/specs/account-and-auth.md §6.3
 *
 * @api
 */
final class SeasonVoteDeletionHook implements UserDeletionHookInterface
{
    public function __construct(
        private readonly Connection $db,
        private readonly SeasonResults $results,
    ) {
    }

    #[\Override]
    public function preDelete(User $user): void
    {
        $id = (int) $user->getId();
        /** @var list<array{region_id: int|string, category: string}> $lists */
        $lists = $this->db->fetchAllAssociative('SELECT DISTINCT region_id, category FROM season_vote WHERE user_id = :u', ['u' => $id]);
        foreach ($lists as $list) {
            $type = ItemType::from($list['category']);
            $bikes = ItemType::QualityRides === $type ? [null, ...BikeType::cases()] : [null];
            foreach ($bikes as $bike) {
                $this->results->freezeClosed(new ListKey((int) $list['region_id'], $type, $bike), true);
            }
        }

        $this->db->executeStatement('DELETE FROM season_vote WHERE user_id = :u', ['u' => $id]);
    }
}
