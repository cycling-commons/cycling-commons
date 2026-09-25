<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace App\Contribution;

/**
 * Decodes climb-editor route/grad/steep JSON. Coordinates are stored [lat, lng]
 * (docs/specs/climb-elevation.md §4).
 *
 * @api
 */
final class ClimbGeometry
{
    /** Upper bound on route/grad list lengths. */
    public const int MAX_POINTS = 8000;

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{route?: list<array{0:float,1:float}>, grad?: list<int|float>, steep?: array{at:array{0:float,1:float}, pct:string, manual:bool}, steepPoint?: array{at:array{0:float,1:float}, pct:string, note:string}, avg?: string}
     */
    public static function fromPayload(array $payload): array
    {
        $out = [];
        if (self::present($payload['route'] ?? null)) {
            $out['route'] = self::coords(self::json((string) $payload['route']));
        }
        if (self::present($payload['grad'] ?? null)) {
            $out['grad'] = self::numbers(self::json((string) $payload['grad']));
        }
        if (self::present($payload['steep'] ?? null)) {
            $out['steep'] = self::steep(self::json((string) $payload['steep']));
        }
        /* Rider steepest point is distinct from measured `steep` (docs/specs/climb-elevation.md §5a). */
        if (self::present($payload['steepPoint'] ?? null)) {
            $out['steepPoint'] = self::steepPoint(self::json((string) $payload['steepPoint']));
        }
        // Editor's ascent-only average, kept as the display string.
        if (self::present($payload['avg'] ?? null)) {
            $out['avg'] = self::gradientText((string) $payload['avg']);
        }

        return $out;
    }

    private static function present(mixed $v): bool
    {
        return null !== $v && '' !== $v;
    }

    private static function json(string $raw): mixed
    {
        try {
            return json_decode($raw, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('malformed climb geometry JSON: '.$e->getMessage());
        }
    }

    /** @return list<array{0:float,1:float}> */
    private static function coords(mixed $v): array
    {
        if (!\is_array($v) || [] === $v || !array_is_list($v)) {
            throw new \InvalidArgumentException('route must be a non-empty list of [lat,lng] pairs');
        }
        if (\count($v) > self::MAX_POINTS) {
            throw new \InvalidArgumentException(sprintf('route exceeds %d points', self::MAX_POINTS));
        }
        $out = [];
        foreach ($v as $pair) {
            if (!\is_array($pair) || 2 !== \count($pair) || !\is_numeric($pair[0] ?? null) || !\is_numeric($pair[1] ?? null)) {
                throw new \InvalidArgumentException('each route point must be [lat,lng]');
            }
            if (!is_finite((float) $pair[0]) || !is_finite((float) $pair[1])) {
                throw new \InvalidArgumentException('each route point must be [lat,lng]');
            }
            // Coordinates are stored [lat, lng].
            if ((float) $pair[0] < -90 || (float) $pair[0] > 90 || (float) $pair[1] < -180 || (float) $pair[1] > 180) {
                throw new \InvalidArgumentException('route point out of range (lat -90..90, lng -180..180)');
            }
            $out[] = [(float) $pair[0], (float) $pair[1]];
        }

        return $out;
    }

    /** @return list<int|float> */
    private static function numbers(mixed $v): array
    {
        if (!\is_array($v) || !array_is_list($v)) {
            throw new \InvalidArgumentException('grad must be a list of numbers');
        }
        if (\count($v) > self::MAX_POINTS) {
            throw new \InvalidArgumentException(sprintf('grad exceeds %d values', self::MAX_POINTS));
        }
        $out = [];
        foreach ($v as $n) {
            if (!\is_int($n) && !\is_float($n)) {
                throw new \InvalidArgumentException('grad values must be numeric');
            }
            if (!is_finite((float) $n)) {
                throw new \InvalidArgumentException('grad values must be numeric');
            }
            $out[] = $n;
        }

        return $out;
    }

    /** @return array{at:array{0:float,1:float}, pct:string, manual:bool} */
    private static function steep(mixed $v): array
    {
        $at = \is_array($v) ? ($v['at'] ?? null) : null;
        if (!\is_array($at) || 2 !== \count($at) || !\is_numeric($at[0] ?? null) || !\is_numeric($at[1] ?? null)) {
            throw new \InvalidArgumentException('steep.at must be [lat,lng]');
        }
        if (!is_finite((float) $at[0]) || !is_finite((float) $at[1])) {
            throw new \InvalidArgumentException('steep.at must be [lat,lng]');
        }

        $pctRaw = $v['pct'] ?? '';
        if (\is_array($pctRaw)) {
            throw new \InvalidArgumentException('steep.pct must be a scalar gradient value');
        }
        $pct = (string) $pctRaw;
        /* Accept "~20%" — that is what seeded climbs already store. */
        if ('' !== $pct && 1 !== preg_match('/^~?\d{1,2}(\.\d{1,2})?%?$/', $pct)) {
            throw new \InvalidArgumentException('steep.pct must look like a gradient, e.g. "12", "12.5%" or "~20%"');
        }

        return [
            'at' => [(float) $at[0], (float) $at[1]],
            'pct' => $pct,
            'manual' => (bool) ($v['manual'] ?? false),
        ];
    }

    /**
     * Rider-placed steepest point, its own attribute rather than an edit to `steep`
     * (docs/specs/climb-elevation.md §5a).
     *
     * @return array{at:array{0:float,1:float}, pct:string, note:string}
     */
    private static function steepPoint(mixed $v): array
    {
        $at = \is_array($v) ? ($v['at'] ?? null) : null;
        if (!\is_array($at) || 2 !== \count($at) || !\is_numeric($at[0] ?? null) || !\is_numeric($at[1] ?? null)) {
            throw new \InvalidArgumentException('steepPoint.at must be [lat,lng]');
        }
        $lat = (float) $at[0];
        $lng = (float) $at[1];
        if (!is_finite($lat) || !is_finite($lng) || $lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
            throw new \InvalidArgumentException('steepPoint.at must be [lat,lng]');
        }

        $pctRaw = $v['pct'] ?? '';
        if (\is_array($pctRaw)) {
            throw new \InvalidArgumentException('steepPoint.pct must be a scalar gradient value');
        }
        $pct = trim((string) $pctRaw);
        if ('' !== $pct && 1 !== preg_match('/^~?\d{1,2}(\.\d{1,2})?%?$/', $pct)) {
            throw new \InvalidArgumentException('steepPoint.pct must look like a gradient, e.g. "26%"');
        }

        $noteRaw = $v['note'] ?? '';
        if (\is_array($noteRaw)) {
            throw new \InvalidArgumentException('steepPoint.note must be text');
        }
        // Landmark, not a paragraph.
        $note = mb_substr(trim((string) $noteRaw), 0, 120);

        return ['at' => [$lat, $lng], 'pct' => $pct, 'note' => $note];
    }

    /** Same gradient shape as `steep.pct`. */
    private static function gradientText(string $raw): string
    {
        $v = trim($raw);
        if ('' !== $v && 1 !== preg_match('/^~?\d{1,2}(\.\d{1,2})?%?$/', $v)) {
            throw new \InvalidArgumentException('avg must look like a gradient, e.g. "5.7%"');
        }

        return $v;
    }
}
