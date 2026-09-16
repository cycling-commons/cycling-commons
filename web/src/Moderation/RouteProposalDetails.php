<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Moderation;

use App\Catalog\BikeType;
use App\Catalog\DifficultyVocabulary;
use App\Catalog\Season;

/**
 * The route details a rider fills in on /propose-route, read back from a
 * route's `attributes` for the curator's review page: one row per form field,
 * in the form's order, under the form's own label.
 *
 * `values` are translation keys (or msgids) when `translate` is true; the
 * rider's note is their own text and is shown as written. A field the rider
 * left unset has no values.
 *
 * @see docs/specs/route-domain.md §5, §9
 *
 * @api
 */
final class RouteProposalDetails
{
    /**
     * Stored `gradientLimited` value => its label key (route-domain.md §9).
     *
     * @var array<string, string>
     */
    public const array GRADIENT_LABELS = [
        'No' => 'propose_route.gradient_none',
        '≤6%' => 'propose_route.gradient_6',
        '≤9%' => 'propose_route.gradient_9',
    ];

    /**
     * @param array<string, mixed> $attributes
     *
     * @return list<array{key:string, label:string, values:list<string>, translate:bool}>
     */
    public static function rows(array $attributes): array
    {
        $difficulty = DifficultyVocabulary::canonical($attributes['difficulty'] ?? null);
        $gradient = self::text($attributes['gradientLimited'] ?? null);

        return [
            self::row('difficulty', 'propose_route.difficulty_label', null !== $difficulty ? [$difficulty['label']] : []),
            self::row('dominant_surface', 'propose_route.surface_label', self::texts($attributes['dominantSurface'] ?? null)),
            self::row('season', 'propose_route.season_label', array_map(
                static fn (string $s): string => Season::tryFrom(strtolower($s))?->labelKey() ?? $s,
                self::texts($attributes['season'] ?? null),
            )),
            self::row('bike_types', 'propose_route.bike_types_label', array_map(
                static fn (string $b): string => BikeType::tryFrom($b)?->labelKey() ?? $b,
                self::texts($attributes['bikeTypes'] ?? null),
            )),
            self::row('gradient', 'propose_route.gradient_label', null !== $gradient ? [self::GRADIENT_LABELS[$gradient] ?? $gradient] : []),
            self::row('rider_note', 'propose_route.note_label', self::texts($attributes['note'] ?? null), translate: false),
        ];
    }

    /**
     * @param list<string> $values
     *
     * @return array{key:string, label:string, values:list<string>, translate:bool}
     */
    private static function row(string $key, string $label, array $values, bool $translate = true): array
    {
        return ['key' => $key, 'label' => $label, 'values' => $values, 'translate' => $translate];
    }

    /** @return list<string> a scalar or a list of scalars, blanks dropped */
    private static function texts(mixed $value): array
    {
        $list = \is_array($value) ? $value : [$value];

        return array_values(array_filter(array_map(self::text(...), $list), static fn (?string $v): bool => null !== $v));
    }

    private static function text(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        $value = trim($value);

        return '' !== $value ? $value : null;
    }
}
