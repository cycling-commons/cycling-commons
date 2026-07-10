<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

/**
 * The shared bike-type enum (route-domain spec D6): one value set for route
 * suitability declarations and — in later phases — typed votes and ride
 * confirmations. Handbike, Recumbent, Trike and Tandem are deliberately
 * first-class hardware types (not folded into a generic "other"): each has
 * real route-suitability implications (turning radius, width, ground
 * clearance) distinct from the general Road/Gravel/MTB/E-bike group.
 *
 * @api Route-domain vocabulary.
 */
enum BikeType: string
{
    case Road = 'Road';
    case Gravel = 'Gravel';
    case Mtb = 'MTB';
    case Ebike = 'E-bike';
    case Handbike = 'Handbike';
    case Recumbent = 'Recumbent';
    case Trike = 'Trike';
    case Tandem = 'Tandem';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }

    /**
     * The specialty/accessibility hardware types whose best-of lists are gated
     * by a route's declared suitability (route-domain spec §14 P4-D4) — their
     * physical constraints (width, turning radius, clearance) make an
     * undeclared route a real mismatch, unlike the general Road/Gravel/MTB/E-bike
     * group where a vote is signal enough.
     *
     * @api Consumed by RouteRankingService's SQL builder.
     */
    public function isSpecialty(): bool
    {
        return match ($this) {
            self::Handbike, self::Recumbent, self::Trike, self::Tandem => true,
            default => false,
        };
    }
}
