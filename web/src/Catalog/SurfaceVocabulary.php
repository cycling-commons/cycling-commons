<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

/**
 * Declared vs measured surface vocabulary. Do not validate stored A-layer values against DECLARABLE — harvested rows hold extra labels.
 *
 * @see docs/specs/route-domain.md §9
 *
 * @api
 */
final class SurfaceVocabulary
{
    /** @var list<string> Form/route vocabulary. Harvested A-layer rows also store harvester-only labels; Mixed is a bucket output, never stored. */
    public const array DECLARABLE = [
        'Asphalt', 'Concrete', 'Paving stones', 'Sett — pavé', 'Compacted', 'Fine gravel', 'Gravel', 'Dirt', 'Rock',
    ];

    /** @var array<string, string> A-layer / harvester labels → coarse Asphalt/Mixed/Gravel. Both spellings of sett are kept. */
    public const array BUCKETS = [
        'Asphalt' => 'Asphalt', 'Concrete' => 'Asphalt', 'Cycleway · RAVeL' => 'Asphalt',
        'Paving stones' => 'Mixed', 'Sett — pavé' => 'Mixed', 'Sett (pavé)' => 'Mixed', 'Compacted' => 'Mixed',
        'Unhewn cobblestone' => 'Mixed', 'Cobblestone' => 'Mixed',
        'Fine gravel' => 'Gravel', 'Gravel' => 'Gravel', 'Dirt' => 'Gravel', 'Rock' => 'Gravel',
    ];

    /**
     * Tile class → line colour. The only palette: the map lines (render.js
     * SURFACE_STYLE via CC_SURFACE_COLOURS), the legend box, the Key panel and
     * the /map-key page all read it. Red is reserved for `unverified` (drawn
     * dashed).
     *
     * @var array<string, string>
     */
    public const array LINE_COLOUR = [
        'paved' => '#577A71',
        'gravel' => '#C8923A',
        'pave' => '#B89AD9',
        'dirt' => '#6E5849',
        'rock' => '#98A1AB',
        'unverified' => '#D92D20',
    ];

    /** @var array<string, string> Tile class → declarable label. `cycleway` and `unverified` are absent: they confirm nothing. */
    public const array TILE_CLASS = [
        'paved' => 'Asphalt',
        'gravel' => 'Gravel',
        'pave' => 'Sett — pavé',
        'dirt' => 'Dirt',
        'rock' => 'Rock',
    ];

    /** @var array<string, string> OSM `surface=` (Scout) → declarable label. Absent means the rider chooses. */
    public const array FROM_OSM = [
        'asphalt' => 'Asphalt',
        'concrete' => 'Concrete',
        'paving_stones' => 'Paving stones',
        'sett' => 'Sett — pavé',
        'cobblestone' => 'Sett — pavé',
        'compacted' => 'Compacted',
        'fine_gravel' => 'Fine gravel',
        'gravel' => 'Gravel',
        'ground' => 'Dirt',
        'dirt' => 'Dirt',
        'earth' => 'Dirt',
        'sand' => 'Dirt',
        'rock' => 'Rock',
    ];

    /** The declarable label for a raw OSM surface value, or null. */
    public static function fromOsmValue(string $value): ?string
    {
        return self::FROM_OSM[strtolower(trim($value))] ?? null;
    }

    /** The declarable label a tile class confirms to, or null when it confirms nothing. */
    public static function fromTileClass(string $cls): ?string
    {
        return self::TILE_CLASS[$cls] ?? null;
    }

    /** @var array<string, string> Stored A-layer label → map class. Covers DECLARABLE and harvester-only spellings. */
    public const array TO_TILE_CLASS = [
        'Asphalt' => 'paved', 'Concrete' => 'paved', 'Paving stones' => 'paved',
        'Cycleway · RAVeL' => 'paved',
        'Sett — pavé' => 'pave', 'Sett (pavé)' => 'pave',
        'Cobblestone' => 'pave', 'Unhewn cobblestone' => 'pave',
        'Compacted' => 'gravel', 'Fine gravel' => 'gravel', 'Gravel' => 'gravel',
        'Dirt' => 'dirt',
        'Rock' => 'rock',
        'Surface unverified' => 'unverified',
    ];

    /** The map class a stored surface label draws in, or null when unknown. */
    public static function tileClassFor(?string $surface): ?string
    {
        return null === $surface ? null : (self::TO_TILE_CLASS[trim($surface)] ?? null);
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
