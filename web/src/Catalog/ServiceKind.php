<?php
// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
declare(strict_types=1);

namespace App\Catalog;

/**
 * The three kinds of "D · Bike services" (OSM data architecture spec §5):
 * a staffed shop, an unmanned self-service station, or a public pump.
 * Only a shop has meaningful opening hours; the others are 24/7 by nature.
 */
enum ServiceKind: string
{
    case Shop = 'shop';
    case Station = 'station';
    case Pump = 'pump';

    /** @param array<string,string> $tags raw OSM tags */
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

    public function label(): string
    {
        return match ($this) {
            self::Shop => 'Bike shop',
            self::Station => 'Self-service station',
            self::Pump => 'Public pump',
        };
    }
}
