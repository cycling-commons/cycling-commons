<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace App\Catalog;

/**
 * D · Bike services: staffed shop, unmanned station, or public pump. Only a shop has meaningful opening hours.
 *
 * @see docs/specs/osm-data-architecture.md §5
 */
enum ServiceKind: string
{
    case Shop = 'shop';
    case Station = 'station';
    case Pump = 'pump';

    /**
     * PHP copy of the pipeline mapping so CoverageContractTest can pin it.
     *
     * @see docs/specs/coverage-provider.md §7
     *
     * @api
     *
     * @param array<string,string> $tags raw OSM tags
     */
    public static function fromOsmTags(array $tags): ?self
    {
        return match (true) {
            ($tags['shop'] ?? null) === 'bicycle' => self::Shop,
            ($tags['amenity'] ?? null) === 'bicycle_repair_station' => self::Station,
            ($tags['amenity'] ?? null) === 'compressed_air' => self::Pump,
            default => null,
        };
    }

    /** Map the harvester's human label (item.attributes.t) onto a kind. */
    public static function fromLegacyLabel(?string $t): ?self
    {
        return match ($t) {
            'Bike shop' => self::Shop,
            'Repair station', 'Public repair station', 'E-bike charging station' => self::Station,
            'Pump' => self::Pump,
            default => null,
        };
    }

    public function hasOpeningHours(): bool
    {
        return self::Shop === $this;
    }
}
