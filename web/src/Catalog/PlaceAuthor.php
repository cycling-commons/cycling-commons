<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

use Doctrine\DBAL\Connection;

/**
 * Who a place's author is, for a statement of reasons (DSA Article 17).
 *
 * The rider whose approved new-place submission created the item: one person
 * pressed send, and a curator accepted it. A place seeded from OpenStreetMap
 * or a provider, or created before submissions were kept, has no author, and
 * neither does one whose author deleted their account (`user_id` NULL) or one
 * the system made (`user_id` 0). Later edits by other riders do not make them
 * its author: they changed it, they did not add it.
 *
 * @see docs/specs/content-reports.md §8
 *
 * @api
 */
final readonly class PlaceAuthor
{
    public function __construct(private Connection $db)
    {
    }

    /** The author's user id, or null when the place has none. */
    public function of(int $itemId): ?int
    {
        $id = $this->db->fetchOne(
            'SELECT s.user_id FROM submission s
               JOIN users u ON u.id = s.user_id
              WHERE s.item_id = :item AND s.type = :new AND s.status = :approved AND s.user_id <> 0
              ORDER BY s.id ASC LIMIT 1',
            ['item' => $itemId, 'new' => SubmissionType::NewItem->value, 'approved' => SubmissionStatus::Approved->value],
        );

        return false === $id || null === $id ? null : (int) $id;
    }
}
