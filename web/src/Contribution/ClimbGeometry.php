<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
declare(strict_types=1);

namespace App\Contribution;

/**
 * Decodes + validates the climb-editor's route/grad/steep JSON payload into
 * typed PHP arrays. One place for the shape rules so the contribution service
 * stays thin and the rules are unit-tested. Coordinates are stored [lat, lng]
 * to match the existing `route`/`steep.at` attribute shape and map.js. The
 * validator only checks pairs of finite numbers - the editor is responsible
 * for emitting them in [lat, lng] order at the storage boundary.
 *
 * @api Used by CatalogContributionService.
 */
final class ClimbGeometry
{
    /**
     * Upper bound on route/grad list lengths. A drawn climb is a handful of
     * points; thousands means a hand-crafted or runaway payload. Capping here
     * (before the value reaches the submission/item tables and, on approve,
     * every visitor's /map/catalog.json) bounds storage and response size.
     */
    private const int MAX_POINTS = 2000;

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{route?: list<array{0:float,1:float}>, grad?: list<int|float>, steep?: array{at:array{0:float,1:float}, pct:string, manual:bool}}
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
            // Coordinates are stored [lat, lng] (see class docblock).
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

        // $v is known to be an array here (we just read $v['at'] from it).
        $pctRaw = $v['pct'] ?? '';
        if (\is_array($pctRaw)) {
            throw new \InvalidArgumentException('steep.pct must be a scalar gradient value');
        }
        $pct = (string) $pctRaw;
        // A gradient percentage: up to two digits, optional decimal, optional
        // trailing '%'. Rejects arbitrary strings ("<script>", "Array", …) that
        // would otherwise flow verbatim into published attributes.
        if ('' !== $pct && 1 !== preg_match('/^\d{1,2}(\.\d{1,2})?%?$/', $pct)) {
            throw new \InvalidArgumentException('steep.pct must look like a gradient, e.g. "12" or "12.5%"');
        }

        return [
            'at' => [(float) $at[0], (float) $at[1]],
            'pct' => $pct,
            'manual' => (bool) ($v['manual'] ?? false),
        ];
    }
}
