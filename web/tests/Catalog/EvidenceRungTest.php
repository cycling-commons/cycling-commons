<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\CustodyTier;
use App\Catalog\EvidenceRung;
use PHPUnit\Framework\TestCase;

/**
 * The evidence ladder (data-provider-hierarchy.md §6.7.7): kind of evidence
 * first, then how many, then how recent. Rungs 10 to 12 follow the verified
 * state and never a window, because "no `?` means verified state" is one
 * rule read in both directions.
 */
final class EvidenceRungTest extends TestCase
{
    private const string NOW = '2026-09-10 12:00:00';

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }

    private function fresh(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-08-01 09:00:00');
    }

    private function aged(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2025-08-01 09:00:00');
    }

    public function testAnUndatedOsmNodeIsTheBottomRung(): void
    {
        self::assertSame(1, EvidenceRung::of(CustodyTier::Gross, null, 0, null, null, $this->now()));
    }

    public function testOurOwnUnconfirmedRowIsItsOwnRung(): void
    {
        self::assertSame(3, EvidenceRung::of(CustodyTier::Ours, null, 0, null, null, $this->now()), 'a rider put it here and a curator accepted it: never a gross provider\'s fossil');
    }

    public function testAGrossProviderStillCarryingTheRowIsALiveClaim(): void
    {
        self::assertSame(2, EvidenceRung::of(CustodyTier::Gross, $this->fresh(), 0, null, null, $this->now()));
    }

    public function testASpecialtyProviderWithNoDatesSitsAboveGeneric(): void
    {
        self::assertSame(4, EvidenceRung::of(CustodyTier::Specialty, null, 0, null, null, $this->now()));
    }

    public function testACurrentHarvestIsALiveClaim(): void
    {
        self::assertSame(5, EvidenceRung::of(CustodyTier::Specialty, $this->fresh(), 0, null, null, $this->now()));
    }

    public function testAHarvestOutsideTheWindowIsAFossilClaimAgain(): void
    {
        self::assertSame(
            4,
            EvidenceRung::of(CustodyTier::Specialty, $this->aged(), 0, null, null, $this->now()),
            'a row the publisher stopped carrying falls off rung 5 by itself',
        );
    }

    public function testAWitnessInsideTheWindowOutranksALiveClaim(): void
    {
        $liveClaim = EvidenceRung::of(CustodyTier::Specialty, $this->fresh(), 0, null, null, $this->now());
        $witness = EvidenceRung::of(CustodyTier::Gross, null, 0, null, $this->fresh(), $this->now());

        self::assertSame(8, $witness);
        self::assertGreaterThan($liveClaim, $witness, 'A dated witness beats an undated claim.');
    }

    /**
     * Kind of evidence is the first tie-break, so a witness that aged out
     * still sits above every claim, live or fossil. It only drops under the
     * witnesses inside the window, and it wears the badge again.
     */
    public function testAWitnessOutsideTheWindowStaysAboveEveryClaimButWearsTheBadge(): void
    {
        $liveClaim = EvidenceRung::of(CustodyTier::Specialty, $this->fresh(), 0, null, null, $this->now());
        $agedWitness = EvidenceRung::of(CustodyTier::Gross, null, 0, null, $this->aged(), $this->now());

        self::assertSame(7, $agedWitness);
        self::assertGreaterThan($liveClaim, $agedWitness);
        self::assertTrue(EvidenceRung::showsQuestionBadge($agedWitness));
    }

    public function testOneRiderIsAWitnessButNotYetVerified(): void
    {
        $rung = EvidenceRung::of(CustodyTier::Specialty, $this->fresh(), 1, $this->fresh(), null, $this->now());

        self::assertSame(9, $rung, 'custody does not gate the rider rung: a register tap one rider stood at is rung 9');
        self::assertTrue(EvidenceRung::showsQuestionBadge($rung), 'one rider is below map.item_verify_threshold');
    }

    public function testOneRiderWhoseConfirmationAgedOutIsAnAgedWitness(): void
    {
        self::assertSame(7, EvidenceRung::of(CustodyTier::Ours, null, 1, $this->aged(), null, $this->now()));
    }

    public function testThresholdRidersEarnTheVerifiedRung(): void
    {
        self::assertSame(10, EvidenceRung::of(CustodyTier::Ours, null, 2, $this->fresh(), null, $this->now()));
    }

    public function testTheThresholdIsAParameter(): void
    {
        self::assertSame(
            9,
            EvidenceRung::of(CustodyTier::Ours, null, 2, $this->fresh(), null, $this->now(), 6, 3),
            'two riders under a threshold of three are still one short',
        );
    }

    public function testTheVerifiedStateOutlivesARaisedThreshold(): void
    {
        self::assertSame(
            10,
            EvidenceRung::of(CustodyTier::Ours, null, 2, $this->fresh(), null, $this->now(), 6, 3, 'riders'),
            'state is state: raising the threshold later does not unverify a row',
        );
    }

    public function testTheVerifiedStateNeverAgesOut(): void
    {
        $rung = EvidenceRung::of(CustodyTier::Ours, null, 2, $this->aged(), null, $this->now(), 6, 2, 'riders');

        self::assertSame(10, $rung);
        self::assertFalse(EvidenceRung::showsQuestionBadge($rung), 'the stale ring is the freshness signal, never the ?');
    }

    public function testACuratorAloneIsRungEleven(): void
    {
        self::assertSame(11, EvidenceRung::of(CustodyTier::Ours, null, 1, $this->fresh(), null, $this->now(), 6, 2, 'curator'));
    }

    public function testTwoIndependentWitnessesOutrankOneCurator(): void
    {
        self::assertSame(
            10,
            EvidenceRung::of(CustodyTier::Ours, null, 2, $this->fresh(), null, $this->now(), 6, 2, 'curator'),
            'once the threshold is met the receipt is two people, whoever flipped the state',
        );
    }

    public function testFiveRidersIsTheTop(): void
    {
        self::assertSame(12, EvidenceRung::of(CustodyTier::Ours, null, 5, $this->fresh(), null, $this->now()));
        self::assertSame(12, EvidenceRung::of(CustodyTier::Ours, null, 5, $this->fresh(), null, $this->now(), 6, 2, 'curator'));
    }

    public function testTheWindowIsAParameter(): void
    {
        $twoYears = EvidenceRung::of(CustodyTier::Gross, null, 0, null, new \DateTimeImmutable('2025-01-01 09:00:00'), $this->now(), 24);

        self::assertSame(8, $twoYears, 'a 24 month window keeps a 20 month old witness fresh');
    }

    public function testTheQuestionBadgeIsExactlyTheRungsWithNoWitnessOnRecord(): void
    {
        foreach ([1, 2, 3, 4, 5, 6, 7, 9] as $rung) {
            self::assertTrue(EvidenceRung::showsQuestionBadge($rung), "rung {$rung} keeps the ?");
        }
        foreach ([8, 10, 11, 12] as $rung) {
            self::assertFalse(EvidenceRung::showsQuestionBadge($rung), "rung {$rung} drops the ?");
        }
    }

    public function testGradeIsCoarserThanTheRung(): void
    {
        self::assertSame('claimed', EvidenceRung::grade(1));
        self::assertSame('claimed', EvidenceRung::grade(3));
        self::assertSame('claimed', EvidenceRung::grade(4));
        self::assertSame('attested', EvidenceRung::grade(5));
        self::assertSame('attested', EvidenceRung::grade(9));
        self::assertSame('minimum', EvidenceRung::grade(10));
        self::assertSame('minimum', EvidenceRung::grade(11));
        self::assertSame('high', EvidenceRung::grade(12));
    }

    public function testARungOutsideTheLadderIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        EvidenceRung::grade(13);
    }
}
