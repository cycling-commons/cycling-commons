<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Contribution;

use App\Catalog\Entity\RecommendedRoute;

/**
 * What a proposer's save of their waiting proposal did: the route as saved,
 * and the fields the proposer changed that a curator's edit holds, which kept
 * the curator's value.
 *
 * @see docs/specs/route-domain.md §4.6
 *
 * @api
 */
final readonly class RouteRevision
{
    /**
     * @param list<string> $curatorKept keys from {@see \App\Catalog\RouteMetadata::EDITABLE_FIELDS}
     */
    public function __construct(
        public RecommendedRoute $route,
        public array $curatorKept,
    ) {
    }
}
