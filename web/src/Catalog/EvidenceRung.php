<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

/**
 * The evidence ladder, 1 to 11 (docs/specs/data-provider-hierarchy.md §6.7.7).
 *
 * Ordered by three tie-breaks in this order: the KIND of evidence, then how
 * many, then how recent. Three kinds, and they are not close. A fossil claim
 * was typed once and carries no interpretable date. A live claim is a source
 * that republished this record recently without retracting it. A witness is a
 * human at the point on a known date.
 *
 *  1  a fossil claim: a gross provider's row, or our own row nobody confirmed
 *  2  gross provider, live claim
 *  3  specialty provider, fossil claim
 *  4  specialty provider, live claim
 *  5  specialty provider with a per-record operational status (not produced
 *     yet: the registry has no field naming which attribute carries it)
 *  6  a witness that aged out of the window; still a witness, so above
 *     every claim, but the badge returns
 *  7  a published witness inside the window (an OSM check_date, a register's
 *     dated survey)
 *  8  one rider inside the window, below map.item_verify_threshold
 *  9  the verified state, earned by map.item_verify_threshold riders
 * 10  the verified state, earned by one curator's word
 * 11  the verified state, and five or more riders
 *
 * Rungs 9 to 11 follow the state and never a window. "No `?` means verified
 * state" (moderation-and-contribution.md §10.1) is one rule read in both
 * directions, and the stale ring, not the badge, is the freshness signal.
 *
 * Pure on purpose: the map, the public API, the curator marker page and the
 * wiki table must give one answer, and a function with no container and no
 * database is the only version of that answer they can all call.
 */
final class EvidenceRung
{
    public const int BOTTOM = 1;
    public const int TOP = 11;

    /** Rungs with no witness on record: a claim, a witness that aged out, or one rider short of the threshold. */
    private const array NO_WITNESS = [1, 2, 3, 4, 5, 6, 8];

    /**
     * @param 'riders'|'curator'|null $verifiedBy the receipt behind a verified state, null while unverified
     */
    public static function of(
        CustodyTier $custody,
        ?\DateTimeImmutable $lastSeenUpstream,
        int $confirmations,
        ?\DateTimeImmutable $newestConfirmation,
        ?\DateTimeImmutable $publishedWitnessDate,
        \DateTimeImmutable $now,
        int $staleMonths = 6,
        int $threshold = 2,
        ?string $verifiedBy = null,
    ): int {
        $window = $now->modify("-{$staleMonths} months");
        $fresh = static fn (?\DateTimeImmutable $d): bool => null !== $d && $d >= $window;

        if ('curator' === $verifiedBy && $confirmations < $threshold) {
            return 10;
        }
        if ('riders' === $verifiedBy || $confirmations >= $threshold) {
            return $confirmations >= 5 ? 11 : 9;
        }
        if ($confirmations >= 1 && $fresh($newestConfirmation)) {
            return 8;
        }
        if ($fresh($publishedWitnessDate)) {
            return 7;
        }
        // A witness that has aged out still happened. Kind of evidence is the
        // first tie-break, so it ranks above every claim, live or fossil.
        if (null !== $publishedWitnessDate || null !== $newestConfirmation) {
            return 6;
        }
        if (CustodyTier::Specialty === $custody) {
            return $fresh($lastSeenUpstream) ? 4 : 3;
        }

        return $fresh($lastSeenUpstream) ? 2 : 1;
    }

    public static function showsQuestionBadge(int $rung): bool
    {
        self::assertOnLadder($rung);

        return \in_array($rung, self::NO_WITNESS, true);
    }

    /**
     * @return 'claimed'|'attested'|'minimum'|'high'
     */
    public static function grade(int $rung): string
    {
        self::assertOnLadder($rung);

        return match (true) {
            $rung >= 11 => 'high',
            $rung >= 9 => 'minimum',
            $rung >= 4 => 'attested',
            default => 'claimed',
        };
    }

    private static function assertOnLadder(int $rung): void
    {
        if ($rung < self::BOTTOM || $rung > self::TOP) {
            throw new \InvalidArgumentException(\sprintf('Rung %d is not on the ladder (%d to %d).', $rung, self::BOTTOM, self::TOP));
        }
    }
}
