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
    /** @var array<string, string> A-layer surface → coarse bucket */
    public const array BUCKETS = [
        'Asphalt' => 'Asphalt', 'Concrete' => 'Asphalt',
        'Paving stones' => 'Mixed', 'Sett — pavé' => 'Mixed', 'Compacted' => 'Mixed',
        'Fine gravel' => 'Gravel', 'Gravel' => 'Gravel', 'Ground' => 'Gravel',
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
