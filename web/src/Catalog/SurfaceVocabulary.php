<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

/**
 * Reconciles the declared coarse dominant-surface vocabulary (Asphalt/Mixed/
 * Gravel) with the A-layer's richer surface set measured by SurfaceProfiler
 * (docs/specs/route-domain.md §12). Buckets measured surfaces and suggests the
 * dominant coarse surface, shown to curators on the Routes desk.
 *
 * @api Read by RouteModerateController::detail().
 */
final class SurfaceVocabulary
{
    /** @var list<string> The full declarable surface vocabulary: the
     *  A-layer set, shared by the A-layer curator `surface` field and the
     *  route `dominantSurface` field. BUCKETS still folds these down to the
     *  coarse measured-vs-declared reconciliation, which stays a simple 3-way
     *  split even though this declarable vocabulary itself is richer.
     *
     *  This list is not exhaustive of what is stored: harvested A-layer rows
     *  also hold harvester-only labels ('Cycleway · RAVeL', 'Sett (pavé)',
     *  'Unhewn cobblestone', 'Cobblestone') and the 'Surface unverified'
     *  placeholder, none declarable here. Those rows are never backfilled and
     *  simply display as-is, so do not validate stored values against this
     *  list. ('Mixed' is only a coarse BUCKETS output, never a stored value.) */
    public const array DECLARABLE = [
        'Asphalt', 'Concrete', 'Paving stones', 'Sett — pavé', 'Compacted', 'Fine gravel', 'Gravel', 'Dirt', 'Rock',
    ];

    /** @var array<string, string> A-layer surface → coarse bucket. Covers both
     *  the curator-dropdown labels (e.g. 'Sett — pavé', em-dash spelling) and the
     *  harvester's emitted labels (route_surfaces.py's SURF, e.g. 'Sett (pavé)',
     *  parens spelling, and 'Cycleway · RAVeL'). The two label sets are not
     *  identical, so both spellings/entries are kept. Dirt/Rock (MTB terrain
     *  carry-in) fold into the same coarse Gravel bucket as Fine gravel: the
     *  declared rider-facing vocabulary stays a 3-way split (Asphalt/Mixed/
     *  Gravel); the precise Dirt/Rock split is curator/A-layer detail only. */
    public const array BUCKETS = [
        'Asphalt' => 'Asphalt', 'Concrete' => 'Asphalt', 'Cycleway · RAVeL' => 'Asphalt',
        'Paving stones' => 'Mixed', 'Sett — pavé' => 'Mixed', 'Sett (pavé)' => 'Mixed', 'Compacted' => 'Mixed',
        'Unhewn cobblestone' => 'Mixed', 'Cobblestone' => 'Mixed',
        'Fine gravel' => 'Gravel', 'Gravel' => 'Gravel', 'Dirt' => 'Gravel', 'Rock' => 'Gravel',
    ];

    /** @var array<string, string> Surface-tile class → the declarable label a
     *  rider is agreeing with when they confirm that stretch. The tile classes
     *  are the pipeline's (pipeline/contract/coverage-contract.json
     *  `surface.classes`), coarser than DECLARABLE by design: one colour has to
     *  stand for a family of OSM values, so `gravel` covers compacted and fine
     *  gravel too. Confirming picks the family's plain name; the rider can still
     *  change it in the form.
     *
     *  Two tile classes are deliberately absent. `cycleway` says what a way IS,
     *  not what it is made of — there is nothing to agree with. `unverified` is
     *  the absence of a claim, so confirming it would be confirming nothing. */
    public const array TILE_CLASS = [
        'paved' => 'Asphalt',
        'gravel' => 'Gravel',
        'pave' => 'Sett — pavé',
        'dirt' => 'Dirt',
        'rock' => 'Rock',
    ];

    /** The declarable label a tile class confirms to, or null when it confirms nothing. */
    public static function fromTileClass(string $cls): ?string
    {
        return self::TILE_CLASS[$cls] ?? null;
    }

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
