<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

/**
 * Reconciles the declared coarse dominant-surface vocabulary (Asphalt/Mixed/
 * Gravel) with the A-layer's richer surface set measured by SurfaceProfiler
 * (route-domain spec §12 P2-D3). Buckets measured surfaces and suggests the
 * dominant coarse surface, shown to curators on the Routes desk.
 *
 * @api Read by RouteModerateController::detail().
 */
final class SurfaceVocabulary
{
    /** @var list<string> The full declarable surface vocabulary (spec §15) — the
     *  A-layer set, now shared by the A-layer curator `surface` field AND the
     *  route `dominantSurface` field (was the coarse Asphalt/Mixed/Gravel;
     *  supersedes P2-D3's rider-facing 3-way). BUCKETS still folds these to the
     *  coarse measured-vs-declared reconciliation. Legacy 'Mixed' values (from
     *  the old vocabulary) simply display as-is — no backfill. */
    public const array DECLARABLE = [
        'Asphalt', 'Concrete', 'Paving stones', 'Sett — pavé', 'Compacted', 'Fine gravel', 'Gravel', 'Dirt', 'Rock',
    ];

    /** @var array<string, string> A-layer surface → coarse bucket. Covers both
     *  the curator-dropdown labels (e.g. 'Sett — pavé', em-dash spelling) and the
     *  harvester's emitted labels (route_surfaces.py's SURF, e.g. 'Sett (pavé)',
     *  parens spelling, and 'Cycleway · RAVeL') — the two label sets aren't
     *  identical, so both spellings/entries are kept. Dirt/Rock (MTB terrain
     *  carry-in) fold into the same coarse Gravel bucket as Fine gravel —
     *  P2-D3's declared rider-facing vocabulary stays 3-way; the precise
     *  Dirt/Rock split is curator/A-layer detail only. */
    public const array BUCKETS = [
        'Asphalt' => 'Asphalt', 'Concrete' => 'Asphalt', 'Cycleway · RAVeL' => 'Asphalt',
        'Paving stones' => 'Mixed', 'Sett — pavé' => 'Mixed', 'Sett (pavé)' => 'Mixed', 'Compacted' => 'Mixed',
        'Unhewn cobblestone' => 'Mixed', 'Cobblestone' => 'Mixed',
        'Fine gravel' => 'Gravel', 'Gravel' => 'Gravel', 'Dirt' => 'Gravel', 'Rock' => 'Gravel',
    ];

    /** @param array{covered:int, parts:list<array{surface:string, pct:int|float}>}|null $profile */
    public static function suggestFromProfile(?array $profile): ?string
    {
        if (null === $profile || empty($profile['parts'])) {
            return null;
        }
        $totals = ['Asphalt' => 0.0, 'Mixed' => 0.0, 'Gravel' => 0.0];
        foreach ($profile['parts'] as $part) {
            $bucket = self::BUCKETS[$part['surface']] ?? 'Mixed';
            $totals[$bucket] += (float) $part['pct'];
        }
        arsort($totals);

        return array_key_first($totals);
    }
}
