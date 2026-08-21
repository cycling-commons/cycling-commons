<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Contribution;

/**
 * One changed value, rendered for a human. Geometry is summarised, not dumped
 * (docs/specs/moderation-and-contribution.md §5.2a).
 *
 * @api
 */
final class ChangeValue
{
    /** Earth's mean radius in metres. */
    private const float EARTH_M = 6371000.0;

    /**
     * @param mixed $value the raw stored value for $field
     */
    public static function format(string $field, mixed $value): string
    {
        if (null === $value || '' === $value) {
            return '';
        }

        return match ($field) {
            'route' => self::route($value),
            'grad' => self::grad($value),
            'steep' => self::steep($value),
            default => self::scalar($value),
        };
    }

    /**
     * A drawn line as length and endpoints.
     */
    private static function route(mixed $v): string
    {
        if (!\is_array($v) || [] === $v) {
            return self::scalar($v);
        }
        $km = self::lengthKm($v);
        /** @var array<int, mixed> $v */
        $first = $v[array_key_first($v)] ?? null;
        $last = $v[array_key_last($v)] ?? null;

        $parts = [sprintf('%.1f km', $km)];
        if (self::isPair($first) && self::isPair($last)) {
            /* @var array{0: float|int|string, 1: float|int|string} $first */
            /* @var array{0: float|int|string, 1: float|int|string} $last */
            $parts[] = sprintf(
                'foot %s → summit %s',
                self::point($first),
                self::point($last),
            );
        }
        $parts[] = sprintf('%d points', \count($v));

        return implode(' · ', $parts);
    }

    /** Sampled gradient profile as range, not individual numbers. */
    private static function grad(mixed $v): string
    {
        if (!\is_array($v) || [] === $v) {
            return self::scalar($v);
        }
        $nums = array_values(array_filter($v, is_numeric(...)));
        if ([] === $nums) {
            return self::scalar($v);
        }
        $min = min($nums);
        $max = max($nums);

        return sprintf('%d samples · %s%% to %s%%', \count($v), self::num($min), self::num($max));
    }

    /** The steepest-ramp marker: its percentage, and where it sits. */
    private static function steep(mixed $v): string
    {
        if (!\is_array($v)) {
            return self::scalar($v);
        }
        $pct = isset($v['pct']) && \is_scalar($v['pct']) ? (string) $v['pct'] : '';
        $at = $v['at'] ?? null;

        $out = '' !== $pct ? $pct : '—';
        if (self::isPair($at)) {
            /** @var array{0: float|int|string, 1: float|int|string} $at */
            $out .= ' at '.self::point($at);
        }
        if (!empty($v['manual'])) {
            // A hand-placed marker is the rider's judgement, not the profile.
            $out .= ' (placed by hand)';
        }

        return $out;
    }

    /** @param array{0: float|int|string, 1: float|int|string} $p [lat, lng] */
    private static function point(array $p): string
    {
        return sprintf('%.4f, %.4f', (float) $p[0], (float) $p[1]);
    }

    private static function isPair(mixed $p): bool
    {
        return \is_array($p) && 2 === \count($p) && is_numeric($p[0] ?? null) && is_numeric($p[1] ?? null);
    }

    /**
     * Great-circle length of a [lat, lng] polyline, in kilometres.
     *
     * @param array<int, mixed> $coords
     */
    private static function lengthKm(array $coords): float
    {
        $m = 0.0;
        $prev = null;
        foreach ($coords as $c) {
            if (!self::isPair($c)) {
                continue;
            }
            /* @var array{0: float|int|string, 1: float|int|string} $c */
            if (null !== $prev) {
                $m += self::haversine((float) $prev[0], (float) $prev[1], (float) $c[0], (float) $c[1]);
            }
            $prev = $c;
        }

        return $m / 1000;
    }

    private static function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_M * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private static function num(float|int|string $n): string
    {
        $f = (float) $n;

        return rtrim(rtrim(number_format($f, 1, '.', ''), '0'), '.');
    }

    /** Everything that is not geometry: a word, a number, or a list of them. */
    private static function scalar(mixed $v): string
    {
        if (\is_bool($v)) {
            return $v ? '1' : '0';
        }
        if (\is_scalar($v)) {
            return (string) $v;
        }
        if (\is_array($v) && array_is_list($v) && [] !== $v) {
            $flat = array_filter($v, \is_scalar(...));
            if (\count($flat) === \count($v)) {
                return implode(', ', array_map(strval(...), $flat));
            }
        }

        return json_encode($v, \JSON_UNESCAPED_UNICODE) ?: '';
    }
}
