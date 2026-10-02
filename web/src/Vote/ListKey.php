<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Vote;

use App\Catalog\BikeType;
use App\Catalog\ItemType;

/**
 * One list without its round: a region, a votable category and, for routes
 * only, the bike the votes were cast on.
 *
 * @see docs/specs/route-domain.md §8c
 *
 * @api
 */
final readonly class ListKey
{
    public function __construct(
        public int $regionId,
        public ItemType $category,
        public ?BikeType $bike = null,
    ) {
        if (!$category->isVotable()) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a votable category.', $category->value));
        }
        if (null !== $bike && ItemType::QualityRides !== $category) {
            throw new \InvalidArgumentException('Only a route list is narrowed by bike type.');
        }
    }

    /** `season_result.bike_type`: the bike, or '' for the list of every bike. */
    public function bikeColumn(): string
    {
        return null !== $this->bike ? $this->bike->value : '';
    }
}
