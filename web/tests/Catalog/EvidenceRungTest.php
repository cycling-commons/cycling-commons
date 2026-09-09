<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\CustodyTier;
use App\Catalog\EvidenceRung;
use PHPUnit\Framework\TestCase;

/**
 * The evidence ladder (data-provider-hierarchy.md §6.7): kind of evidence
 * first, then how many, then how recent.
 */
final class EvidenceRungTest extends TestCase
{
    private const string NOW = '2026-09-10 12:00:00';

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }

    public function testAnUndatedOsmNodeIsTheBottomRung(): void
    {
        self::assertSame(1, EvidenceRung::of(
            CustodyTier::Gross, null, 0, null, null, $this->now(),
        ));
    }

    public function testAGrossProviderStillCarryingTheRowIsALiveClaim(): void
    {
        self::assertSame(2, EvidenceRung::of(
            CustodyTier::Gross,
            new \DateTimeImmutable('2026-09-06 09:00:00'),
            0, null, null, $this->now(),
        ));
    }

    public function testASpecialtyProviderWithNoDatesSitsAboveGeneric(): void
    {
        self::assertSame(3, EvidenceRung::of(
            CustodyTier::Specialty, null, 0, null, null, $this->now(),
        ));
    }

    public function testACurrentHarvestIsALiveClaim(): void
    {
        self::assertSame(4, EvidenceRung::of(
            CustodyTier::Specialty,
            new \DateTimeImmutable('2026-09-06 09:00:00'),
            0, null, null, $this->now(),
        ));
    }

    public function testAHarvestOutsideTheWindowIsAFossilClaimAgain(): void
    {
        self::assertSame(3, EvidenceRung::of(
            CustodyTier::Specialty,
            new \DateTimeImmutable('2026-01-06 09:00:00'),
            0, null, null, $this->now(),
        ), 'a row the publisher stopped carrying falls off rung 4 by itself');
    }

    public function testAWitnessInsideTheWindowOutranksALiveClaim(): void
    {
        $liveClaim = EvidenceRung::of(
            CustodyTier::Specialty,
            new \DateTimeImmutable('2026-09-06 09:00:00'),
            0, null, null, $this->now(),
        );
        $witness = EvidenceRung::of(
            CustodyTier::Gross, null, 0, null,
            new \DateTimeImmutable('2026-03-15 09:00:00'),
            $this->now(),
        );

        self::assertSame(7, $witness);
        self::assertGreaterThan($liveClaim, $witness, 'A dated witness beats an undated claim.');
    }

    /**
     * Kind of evidence is the first tie-break, so a witness that aged out
     * still sits above every claim, live or fossil. It only drops under the
     * witnesses inside the window, and it wears the badge again.
     */
    public function testAWitnessOutsideTheWindowStaysAboveEveryClaimButWearsTheBadge(): void
    {
        $liveClaim = EvidenceRung::of(
            CustodyTier::Specialty,
            new \DateTimeImmutable('2026-09-06 09:00:00'),
            0, null, null, $this->now(),
        );
        $agedWitness = EvidenceRung::of(
            CustodyTier::Gross, null, 0, null,
            new \DateTimeImmutable('2024-01-01 09:00:00'),
            $this->now(),
        );

        self::assertSame(6, $agedWitness);
        self::assertGreaterThan($liveClaim, $agedWitness);
        self::assertTrue(EvidenceRung::showsQuestionBadge($agedWitness));
    }

    public function testOneRiderIsAWitnessButNotYetVerified(): void
    {
        $rung = EvidenceRung::of(
            CustodyTier::Ours, null, 1,
            new \DateTimeImmutable('2026-08-01 09:00:00'),
            null, $this->now(),
        );

        self::assertSame(8, $rung);
        self::assertTrue(EvidenceRung::showsQuestionBadge($rung), 'one rider is below map.item_verify_threshold');
    }

    public function testTwoOfOurRidersEarnTheVerifiedRung(): void
    {
        self::assertSame(9, EvidenceRung::of(
            CustodyTier::Ours, null, 2,
            new \DateTimeImmutable('2026-08-01 09:00:00'),
            null, $this->now(),
        ));
    }

    public function testFiveRidersIsTheTop(): void
    {
        self::assertSame(11, EvidenceRung::of(
            CustodyTier::Ours, null, 5,
            new \DateTimeImmutable('2026-08-01 09:00:00'),
            null, $this->now(),
        ));
    }

    public function testRiderConfirmationsThatAgedOutAreStillAWitness(): void
    {
        self::assertSame(6, EvidenceRung::of(
            CustodyTier::Ours, null, 2,
            new \DateTimeImmutable('2025-08-01 09:00:00'),
            null, $this->now(),
        ));
    }

    public function testTheWindowIsAParameter(): void
    {
        $twoYears = EvidenceRung::of(
            CustodyTier::Gross, null, 0, null,
            new \DateTimeImmutable('2025-01-01 09:00:00'),
            $this->now(),
            24,
        );

        self::assertSame(7, $twoYears, 'a 24 month window keeps a 20 month old witness fresh');
    }

    public function testTheQuestionBadgeIsExactlyTheRungsWithNoDatedWitness(): void
    {
        foreach ([1, 2, 3, 4, 5, 6, 8] as $rung) {
            self::assertTrue(EvidenceRung::showsQuestionBadge($rung), "rung {$rung} keeps the ?");
        }
        foreach ([7, 9, 10, 11] as $rung) {
            self::assertFalse(EvidenceRung::showsQuestionBadge($rung), "rung {$rung} drops the ?");
        }
    }

    public function testGradeIsCoarserThanTheRung(): void
    {
        self::assertSame('claimed', EvidenceRung::grade(1));
        self::assertSame('claimed', EvidenceRung::grade(3));
        self::assertSame('attested', EvidenceRung::grade(4));
        self::assertSame('attested', EvidenceRung::grade(8));
        self::assertSame('minimum', EvidenceRung::grade(9));
        self::assertSame('minimum', EvidenceRung::grade(10));
        self::assertSame('high', EvidenceRung::grade(11));
    }

    public function testARungOutsideTheLadderIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        EvidenceRung::grade(12);
    }
}
