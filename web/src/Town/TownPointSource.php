<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Town;

/**
 * Where a town lies, read from OpenStreetMap by the server.
 *
 * The point decides which region a town text is filed in and which curators
 * may write it directly, so it never comes from a request.
 *
 * @see docs/specs/map-and-search.md §6.5
 */
interface TownPointSource
{
    /**
     * The element's point, or null when OpenStreetMap no longer has it.
     *
     * @return array{lat: float, lng: float}|null
     *
     * @throws TownSourceUnavailable
     */
    public function point(string $type, int $id): ?array;
}
