<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

use App\Settings\SettingsProviderInterface;
use App\Settings\SettingsRegistry;

/**
 * Turns one served item row into its ItemEvidence, for the map payload and
 * the public API alike (docs/specs/data-provider-hierarchy.md §6.7.7).
 *
 * selectSql() names the columns a query must add; fromRow() reads them. The
 * ladder itself lives in EvidenceRung and is never re-derived in SQL: one
 * definition, one implementation, and the curator marker page must be able to
 * call the same one without a database.
 *
 * Custody is a registry fact. A row is specialty because its provider's
 * registry row carries a scope for the row's letter, ours because this
 * community keeps it (a rider's own row, or a provider row the riders took at
 * the threshold), and gross otherwise. Nothing here judges a publisher.
 *
 * @api
 */
final class ItemEvidenceResolver
{
    /** Sources with no registry row of their own: mirrored data nobody here has taken on. */
    private const array MIRRORED_SOURCES = [ItemSource::Osm, ItemSource::Wikidata];

    public function __construct(
        private readonly ConfirmationFreshness $freshness,
        private readonly SettingsProviderInterface $settings,
    ) {
    }

    /**
     * The SELECT columns fromRow() reads, for an `item` aliased `$i`. The
     * calling query must also select `$i.state`, `$i.source`, `$i.letter`
     * and `$i.imported_at` under their own names.
     *
     * Confirmations count the vouching stances only and never a form answer,
     * the same rows ItemConfirmationService tallies for the state flip, so
     * rung 9 and the verified state can never disagree.
     */
    public static function selectSql(string $i): string
    {
        $vouching = implode(', ', array_map(
            static fn (ConfirmationStance $s): string => "'".$s->value."'",
            ConfirmationStance::vouching(),
        ));
        $witnesses = "FROM item_confirmation ev_c WHERE ev_c.item_id = {$i}.id AND ev_c.source <> 'form' AND ev_c.stance IN ({$vouching})";

        return "({$i}.provider_id IS NOT NULL) AS ev_provider,
                (SELECT jsonb_exists(ev_dp.letters, {$i}.letter) FROM data_provider ev_dp WHERE ev_dp.id = {$i}.provider_id) AS ev_scope,
                (SELECT COUNT(*) {$witnesses}) AS ev_conf,
                (SELECT MAX(ev_c.created_at) {$witnesses}) AS ev_last,
                COALESCE((SELECT {$i}.attributes->>ev_dp.survey_date_attribute FROM data_provider ev_dp
                           WHERE ev_dp.id = {$i}.provider_id AND ev_dp.survey_date_attribute IS NOT NULL),
                         {$i}.attributes->>'check_date') AS ev_witness,
                {$i}.custody_reclaimed_at AS ev_reclaimed";
    }

    /**
     * @param array{state: string, source: string, imported_at: string|null, ev_provider: bool, ev_scope: bool|null, ev_conf: int|string, ev_last: string|null, ev_witness: string|null, ev_reclaimed: string|null, ...} $row
     */
    public function fromRow(array $row, \DateTimeImmutable $now): ItemEvidence
    {
        $threshold = $this->settings->get(SettingsRegistry::MAP_ITEM_VERIFY_THRESHOLD);
        $confirmations = (int) $row['ev_conf'];
        $verifiedBy = ItemState::Verified->value === $row['state']
            ? ($confirmations >= $threshold ? 'riders' : 'curator')
            : null;
        $provider = (bool) $row['ev_provider'];
        $source = ItemSource::tryFrom($row['source']);
        $lastConfirmed = self::date($row['ev_last']);
        // Custody moves both ways (§6.7.2): the provider holds a verified row
        // again from the survey date the harvest wrote, until a confirmation
        // newer than that date lands. Evidence never moves with it.
        $reclaimed = self::date($row['ev_reclaimed'] ?? null);
        $providerHolds = $provider && null !== $reclaimed && (null === $lastConfirmed || $reclaimed >= $lastConfirmed);

        $custody = match (true) {
            null !== $verifiedBy && !$providerHolds => CustodyTier::Ours,
            $provider => (bool) ($row['ev_scope'] ?? false) ? CustodyTier::Specialty : CustodyTier::Gross,
            \in_array($source, self::MIRRORED_SOURCES, true) => CustodyTier::Gross,
            default => CustodyTier::Ours,
        };
        // imported_at is an upstream sighting only where an upstream exists
        // (§6.7.6); every row carries the column, only a provider row means it.
        $lastSeenUpstream = $provider ? self::date($row['imported_at']) : null;

        $rung = EvidenceRung::of(
            $custody,
            $lastSeenUpstream,
            $confirmations,
            $lastConfirmed,
            self::date($row['ev_witness']),
            $now,
            $this->freshness->staleMonths(),
            $threshold,
            $verifiedBy,
        );

        return new ItemEvidence(
            $custody,
            $rung,
            EvidenceRung::grade($rung),
            EvidenceRung::showsQuestionBadge($rung),
            $confirmations,
            $lastConfirmed,
            $lastSeenUpstream,
            $verifiedBy,
        );
    }

    private static function date(?string $value): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            // A published witness date is free text upstream (an OSM
            // check_date can be "2023" or "summer"); unreadable is undated.
            return null;
        }
    }
}
