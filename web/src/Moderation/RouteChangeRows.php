<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Moderation;

use App\Catalog\BikeType;
use App\Catalog\DifficultyVocabulary;
use App\Catalog\RouteMetadata;
use App\Catalog\Season;

/**
 * A `metadata` correction's proposed values, ready to read: one row per field,
 * under the form's own label, with the route's current value beside it. The
 * curator decides on what would change, not on the fact that something did.
 *
 * Values are translation keys (or msgids) when `translate` is true; a rider's
 * note is their own text and is shown as written. An empty side is a field
 * that holds nothing, which the desk renders as a dash like every other empty.
 *
 * @see docs/specs/route-domain.md §7.1
 *
 * @api
 */
final class RouteChangeRows
{
    /**
     * @param mixed $changes the stored `{field: {was, now}}` map, or its JSON
     *
     * @return list<array{key:string, label:string, was:list<string>, now:list<string>, translate:bool}>
     */
    public static function of(mixed $changes): array
    {
        $map = \is_string($changes) ? json_decode($changes, true) : $changes;
        if (!\is_array($map)) {
            return [];
        }

        $rows = [];
        // The forms' order, not the order the rider happened to change them in.
        foreach (RouteMetadata::EDITABLE_FIELDS as $field) {
            if (!\is_array($map[$field] ?? null)) {
                continue;
            }
            $rows[] = [
                'key' => $field,
                'label' => RouteMetadata::LABELS[$field],
                'was' => self::display($field, $map[$field]['was'] ?? null),
                'now' => self::display($field, $map[$field]['now'] ?? null),
                'translate' => 'note' !== $field && RouteMetadata::NAME_FIELD !== $field,
            ];
        }

        return $rows;
    }

    /**
     * One field's value as the reader sees it: the same label vocabulary the
     * drawer and the proposal form use.
     *
     * @return list<string>
     */
    private static function display(string $field, mixed $value): array
    {
        if (null === $value || [] === $value || '' === $value) {
            return [];
        }

        return match ($field) {
            'difficulty' => null !== ($d = DifficultyVocabulary::canonical($value)) ? [$d['label']] : [],
            'season' => array_map(
                static fn (string $s): string => Season::tryFrom(strtolower($s))?->labelKey() ?? $s,
                self::strings($value),
            ),
            'bikeTypes' => array_map(
                static fn (string $b): string => BikeType::tryFrom($b)?->labelKey() ?? $b,
                self::strings($value),
            ),
            'gradientLimited' => array_map(
                static fn (string $g): string => RouteMetadata::GRADIENT_LABELS[$g] ?? $g,
                self::strings($value),
            ),
            default => self::strings($value),
        };
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        $list = \is_array($value) ? $value : [$value];
        $out = [];
        foreach ($list as $v) {
            if (\is_string($v) && '' !== trim($v)) {
                $out[] = trim($v);
            }
        }

        return $out;
    }
}
