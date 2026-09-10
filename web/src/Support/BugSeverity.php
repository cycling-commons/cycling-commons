<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Support;

/**
 * How badly a bug hurts, in the reporter's own judgement.
 *
 * The reporter picks it, and a curator may change it. Deliberately four values
 * and no numeric scale: "is this a 3 or a 4?" is a question nobody answers the
 * same way twice, while "did this stop you riding, or just look wrong?" is a
 * question anyone can answer.
 *
 * @see docs/specs/contact-and-support.md §5
 *
 * @api
 */
enum BugSeverity: string
{
    /** Nobody can use the thing at all, or data is being lost. */
    case Critical = 'critical';
    /** A real part of the site is broken, but there is a way round it. */
    case Major = 'major';
    /** Wrong, annoying, not blocking. The default. */
    case Minor = 'minor';
    /** It only looks wrong. */
    case Cosmetic = 'cosmetic';

    /** @return list<self> form order: worst first, default third */
    public static function all(): array
    {
        return [self::Critical, self::Major, self::Minor, self::Cosmetic];
    }

    public static function fromInput(string $value): self
    {
        return self::tryFrom($value) ?? self::Minor;
    }

    /** Sort weight, so a desk can put critical at the top without a CASE. */
    public function weight(): int
    {
        return match ($this) {
            self::Critical => 0,
            self::Major => 1,
            self::Minor => 2,
            self::Cosmetic => 3,
        };
    }
}
