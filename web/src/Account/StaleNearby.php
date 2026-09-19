<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Account;

use App\Catalog\ConfirmationFreshness;
use App\Catalog\ItemState;
use App\Catalog\ItemType;
use App\Entity\User;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Stale places inside the rider's base area, oldest check first. Same three
 * narrowings as the map: a type whose confirmations age, a served item, and a
 * last tap older than the freshness window.
 *
 * Empty without a base location: there is no "near you" to answer.
 *
 * @see docs/specs/moderation-and-contribution.md §10.1a
 *
 * @api
 */
final class StaleNearby
{
    public function __construct(
        private readonly Connection $db,
        private readonly ConfirmationFreshness $freshness,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function for(User $user, int $limit): array
    {
        $ages = array_values(array_filter(
            array_map(static fn (ItemType $t): string => $t->letter(), ItemType::cases()),
            static fn (string $l): bool => ItemType::fromLetter($l)?->confirmationAges() ?? false,
        ));
        if ([] === $ages) {
            return [];
        }
        $rows = $this->db->fetchAllAssociative(
            'SELECT i.id, i.name, i.letter, last.at AS last_confirmed,
                    round((ST_Distance(i.geom::geography, u.base_point::geography) / 1000)::numeric, 1) AS km
               FROM users u
               JOIN item i ON i.letter IN (:letters) AND i.state IN '.ItemState::servedSqlTuple()."
               JOIN LATERAL (
                    SELECT max(c.created_at) AS at FROM item_confirmation c
                     WHERE c.item_id = i.id AND c.source <> 'form'
               ) last ON last.at IS NOT NULL
              WHERE u.id = :uid
                AND u.base_point IS NOT NULL
                AND last.at < :cut
                AND ST_DWithin(i.geom::geography, u.base_point::geography, u.base_radius_km * 1000)
              ORDER BY last.at ASC, i.id ASC
              LIMIT :limit",
            [
                'uid' => (int) $user->getId(),
                'letters' => $ages,
                'cut' => $this->freshness->staleBefore(new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'limit' => $limit,
            ],
            ['letters' => ArrayParameterType::STRING, 'limit' => ParameterType::INTEGER],
        );
        foreach ($rows as &$row) {
            $row['typeLabelKey'] = ItemType::fromParam((string) $row['letter'])->labelKey();
        }

        /* @var list<array<string, mixed>> */
        return $rows;
    }
}
