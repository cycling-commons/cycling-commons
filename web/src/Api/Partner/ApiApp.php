<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Api\Partner;

/**
 * A registered partner app that presented a valid key (docs/specs/public-api.md §8).
 *
 * @api
 */
final readonly class ApiApp
{
    /** @param list<string> $scopes */
    public function __construct(
        public int $id,
        public string $name,
        public array $scopes,
    ) {
    }

    public function may(string $scope): bool
    {
        return \in_array($scope, $this->scopes, true);
    }
}
