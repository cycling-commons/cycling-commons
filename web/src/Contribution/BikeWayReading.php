<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Contribution;

/**
 * How far a point is from a way a bike may ride.
 *
 * `known` is false when the router could not be asked; that is never read as
 * far, because a warning nobody can explain is worse than none.
 *
 * @see docs/specs/scenic-views.md
 *
 * @api
 */
final readonly class BikeWayReading
{
    /**
     * A scenic view stays within this of a bike way. The same number as the
     * P letter's `nearWay.withinM` in pipeline/contract/coverage-contract.json,
     * which `BikeWayReadingTest` pins.
     */
    public const int SCENIC_WITHIN_M = 250;

    public function __construct(
        public bool $known,
        public ?float $nearestM,
        public int $withinM = self::SCENIC_WITHIN_M,
    ) {
    }

    public static function unknown(): self
    {
        return new self(false, null);
    }

    public function far(): bool
    {
        return $this->known && (null === $this->nearestM || $this->nearestM > $this->withinM);
    }

    /** @return array{known: bool, nearestM: ?int, withinM: int, far: bool} */
    public function toArray(): array
    {
        return [
            'known' => $this->known,
            'nearestM' => null === $this->nearestM ? null : (int) round($this->nearestM),
            'withinM' => $this->withinM,
            'far' => $this->far(),
        ];
    }
}
