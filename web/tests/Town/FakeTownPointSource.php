<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Town;

use App\Town\TownPointSource;
use App\Town\TownSourceUnavailable;

/**
 * Where a town lies, in tests, with no OpenStreetMap request. The test
 * container wires it as the TownPointSource (services.yaml when@test); a test
 * names a town's point with {@see self::place()}, and every other town is
 * unknown to OpenStreetMap.
 */
final class FakeTownPointSource implements TownPointSource
{
    /** @var array<string, array{lat: float, lng: float}> */
    private array $points = [];

    /** How many lookups reached this source, so a test can see one was not made. */
    public int $asked = 0;

    /** Whether OpenStreetMap is down. */
    public bool $down = false;

    public function place(string $osmRef, float $lat, float $lng): void
    {
        $this->points[$osmRef] = ['lat' => $lat, 'lng' => $lng];
    }

    #[\Override]
    public function point(string $type, int $id): ?array
    {
        ++$this->asked;
        if ($this->down) {
            throw new TownSourceUnavailable('osm: down in this test');
        }

        return $this->points[$type.'/'.$id] ?? null;
    }
}
