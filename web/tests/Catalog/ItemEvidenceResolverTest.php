<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\ConfirmationFreshness;
use App\Catalog\CustodyTier;
use App\Catalog\ItemEvidenceResolver;
use App\Settings\SettingsRegistry;
use App\Tests\Settings\FakeSettings;
use PHPUnit\Framework\TestCase;

/**
 * One row in, one answer out, for the map payload and the public API alike
 * (data-provider-hierarchy.md §6.7.7). Custody is read off the registry
 * scope, never judged; the rung comes from EvidenceRung and nowhere else.
 */
final class ItemEvidenceResolverTest extends TestCase
{
    private const string NOW = '2026-09-10 12:00:00';

    private function resolver(int $threshold = 2): ItemEvidenceResolver
    {
        $settings = new FakeSettings([
            SettingsRegistry::MAP_CONFIRMATION_STALE_MONTHS => 6,
            SettingsRegistry::MAP_ITEM_VERIFY_THRESHOLD => $threshold,
        ]);

        return new ItemEvidenceResolver(new ConfirmationFreshness($settings), $settings);
    }

    /**
     * @param array<string, mixed> $over
     *
     * @return array<string, mixed>
     */
    private function row(array $over = []): array
    {
        return $over + [
            'letter' => 'B',
            'source' => 'authority',
            'state' => 'unverified',
            'imported_at' => '2026-09-06 09:00:00',
            'ev_provider' => true,
            'ev_scope' => true,
            'ev_conf' => 0,
            'ev_curator' => false,
            'ev_last' => null,
            'ev_witness' => null,
            'ev_reclaimed' => null,
        ];
    }

    public function testAProviderHoldsAVerifiedRowAgainFromItsSurveyDate(): void
    {
        $e = $this->resolver()->fromRow(
            $this->row(['state' => 'verified', 'ev_conf' => 2, 'ev_last' => '2026-08-01 10:00:00', 'ev_reclaimed' => '2026-09-05 00:00:00']),
            new \DateTimeImmutable(self::NOW),
        );

        self::assertSame(CustodyTier::Specialty, $e->custody, 'the border is the provider\'s again');
        self::assertSame(10, $e->rung, 'the state, and the evidence, never moved');
        self::assertFalse($e->badge);
        self::assertSame('riders', $e->verifiedBy);
    }

    public function testAConfirmationNewerThanTheSurveyHandsCustodyBack(): void
    {
        $e = $this->resolver()->fromRow(
            $this->row(['state' => 'verified', 'ev_conf' => 3, 'ev_last' => '2026-09-08 10:00:00', 'ev_reclaimed' => '2026-09-05 00:00:00']),
            new \DateTimeImmutable(self::NOW),
        );

        self::assertSame(CustodyTier::Ours, $e->custody);
    }

    public function testTheSelectFragmentReadsTheReclaimDate(): void
    {
        self::assertStringContainsString('custody_reclaimed_at AS ev_reclaimed', ItemEvidenceResolver::selectSql('i'));
        self::assertStringContainsString('survey_date_attribute', ItemEvidenceResolver::selectSql('i'), 'a provider\'s own survey date is the published witness');
    }

    public function testARegisterRowInScopeIsSpecialtyAndALiveClaim(): void
    {
        $e = $this->resolver()->fromRow($this->row(), new \DateTimeImmutable(self::NOW));

        self::assertSame(CustodyTier::Specialty, $e->custody);
        self::assertSame(5, $e->rung);
        self::assertSame('attested', $e->grade);
        self::assertTrue($e->badge);
        self::assertNull($e->verifiedBy);
        self::assertSame('2026-09-06', $e->lastSeenUpstream?->format('Y-m-d'));
    }

    public function testAProviderWithoutAScopeForThisLetterIsGross(): void
    {
        $e = $this->resolver()->fromRow($this->row(['ev_scope' => false]), new \DateTimeImmutable(self::NOW));

        self::assertSame(CustodyTier::Gross, $e->custody);
        self::assertSame(2, $e->rung);
    }

    public function testAnOsmOrWikidataRowWithNoRegistryRowIsGross(): void
    {
        foreach (['osm', 'wikidata'] as $source) {
            $e = $this->resolver()->fromRow(
                $this->row(['source' => $source, 'ev_provider' => false, 'ev_scope' => null]),
                new \DateTimeImmutable(self::NOW),
            );

            self::assertSame(CustodyTier::Gross, $e->custody, $source);
            self::assertSame(1, $e->rung, "{$source}: imported_at is not an upstream sighting without a registry row");
            self::assertNull($e->lastSeenUpstream);
        }
    }

    public function testARidersOwnRowIsOursAndAClaimUntilSomebodyConfirms(): void
    {
        $e = $this->resolver()->fromRow(
            $this->row(['source' => 'user', 'ev_provider' => false, 'ev_scope' => null]),
            new \DateTimeImmutable(self::NOW),
        );

        self::assertSame(CustodyTier::Ours, $e->custody);
        self::assertSame(3, $e->rung);
        self::assertTrue($e->badge);
    }

    public function testOneRiderIsAWitness(): void
    {
        $e = $this->resolver()->fromRow(
            $this->row(['ev_conf' => 1, 'ev_last' => '2026-08-20 10:00:00']),
            new \DateTimeImmutable(self::NOW),
        );

        self::assertSame(CustodyTier::Specialty, $e->custody, 'one rider does not take custody');
        self::assertSame(9, $e->rung);
        self::assertSame(1, $e->confirmations);
        self::assertSame('2026-08-20', $e->lastConfirmed?->format('Y-m-d'));
    }

    public function testThresholdRidersTakeTheRecord(): void
    {
        $e = $this->resolver()->fromRow(
            $this->row(['state' => 'verified', 'ev_conf' => 2, 'ev_last' => '2026-08-20 10:00:00']),
            new \DateTimeImmutable(self::NOW),
        );

        self::assertSame(CustodyTier::Ours, $e->custody);
        self::assertSame(10, $e->rung);
        self::assertSame('riders', $e->verifiedBy);
        self::assertFalse($e->badge);
    }

    public function testAVerifiedRowCarriesTheCuratorsWordWhenTheRowSaysSo(): void
    {
        $e = $this->resolver()->fromRow(
            $this->row(['state' => 'verified', 'ev_conf' => 1, 'ev_curator' => true, 'ev_last' => '2026-08-20 10:00:00']),
            new \DateTimeImmutable(self::NOW),
        );

        self::assertSame(11, $e->rung);
        self::assertSame('curator', $e->verifiedBy);
        self::assertSame(CustodyTier::Ours, $e->custody);
    }

    public function testARecordedCuratorKeepsTheReceiptWhenRidersPileOn(): void
    {
        // The receipt names the curator whose word settled it, however many
        // riders stood there afterwards (owner 2026-09-10). The RUNG still
        // follows the stronger evidence: rung 11 is a curator's word and
        // nothing else, so a row at the threshold reads 10.
        $e = $this->resolver()->fromRow(
            $this->row(['state' => 'verified', 'ev_conf' => 2, 'ev_curator' => true, 'ev_last' => '2026-08-20 10:00:00']),
            new \DateTimeImmutable(self::NOW),
        );

        self::assertSame('curator', $e->verifiedBy);
        self::assertSame(10, $e->rung);
    }

    public function testTheCountStillSpeaksWhereNoCuratorRowExists(): void
    {
        // Rows verified before the receipt column existed, and rows an import
        // promoted, carry no by_curator anywhere. Verified below the threshold
        // still means something other than a rider tally earned it.
        $e = $this->resolver()->fromRow(
            $this->row(['state' => 'verified', 'ev_conf' => 0, 'ev_curator' => false]),
            new \DateTimeImmutable(self::NOW),
        );

        self::assertSame('curator', $e->verifiedBy);
        self::assertSame(11, $e->rung);
    }

    public function testAPublishedWitnessDateCountsForAGrossRow(): void
    {
        $e = $this->resolver()->fromRow(
            $this->row(['source' => 'osm', 'ev_provider' => false, 'ev_scope' => null, 'ev_witness' => '2026-07-01']),
            new \DateTimeImmutable(self::NOW),
        );

        self::assertSame(8, $e->rung);
        self::assertFalse($e->badge);
    }

    public function testTheThresholdComesFromSettings(): void
    {
        $e = $this->resolver(3)->fromRow(
            $this->row(['ev_conf' => 2, 'ev_last' => '2026-08-20 10:00:00']),
            new \DateTimeImmutable(self::NOW),
        );

        self::assertSame(9, $e->rung);
    }

    public function testTheSelectFragmentNamesEveryColumnFromRowReads(): void
    {
        $sql = ItemEvidenceResolver::selectSql('i');

        foreach (['ev_provider', 'ev_scope', 'ev_conf', 'ev_last', 'ev_witness'] as $col) {
            self::assertStringContainsString(' AS '.$col, $sql);
        }
        self::assertStringContainsString("<> 'form'", $sql, 'a form answer is not a rider standing there');
    }
}
