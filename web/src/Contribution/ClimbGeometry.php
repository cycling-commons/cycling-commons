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
     * Upper bound on route/grad list lengths — a runaway/hand-crafted payload
     * guard, bounding what reaches the submission/item tables and, on approve,
     * every visitor's /map/catalog.json.
     *
     * **8000, because 2000 rejected real climbs.** "A drawn climb is a handful
     * of points" was true of the 2 km Ardennes climbs this was written for and
     * is false in the Alps: the editor stores the ROUTER's line, and the Susten
     * from Innertkirchen is 2,146 points over 28.5 km at router resolution. So
     * an owner trying to correct that climb's summit got "The drawn shape could
     * not be read — redraw it and try again", on a shape that was perfectly
     * readable and simply longer than a cap nobody had revisited
     * (owner-reported 2026-08-08). Redrawing could never have helped, which is
     * the worst kind of error message.
     *
     * 8000 covers a 100 km climb at the same density — Alto de Letras, the
     * longest paved climb likely to be submitted — with room to spare. The real
     * cost is payload: one such climb is a few hundred kB in catalog.json, so
     * if long climbs become common the answer is simplifying the line on the
     * way in (shape-preserving, NOT even-distance thinning, which cuts hairpin
     * corners), not lowering this back to a number that refuses the road.
     */
    private const int MAX_POINTS = 8000;

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
        /* The RIDER's steepest point, which is a different thing from `steep`.

           `steep` is ours: the steepest sustained 100 m the elevation model can
           see, derived on every redraw. `steepPoint` is a contribution — where
           the wall actually is, on a road someone has ridden. They exist
           separately because the model cannot answer the second question at
           all: Mur de Huy's Chapelle hairpin is smaller than one DEM cell, so
           no window width recovers its ~26%. That is information the dataset
           does not contain and a rider does
           (climb-elevation.md §5a, owner 2026-08-05). */
        if (self::present($payload['steepPoint'] ?? null)) {
            $out['steepPoint'] = self::steepPoint(self::json((string) $payload['steepPoint']));
        }
        // The editor's ascent-only average, already formatted ("5.7%"). Kept as
        // the string it will be displayed as, like `steep.pct`, rather than
        // re-parsed into a number the drawer would have to format again.
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
        /* A gradient percentage: an optional leading '~', up to two digits, an
           optional decimal, an optional trailing '%'. Still rejects arbitrary
           strings ("<script>", "Array", …) that would otherwise flow verbatim
           into published attributes — which is the whole point of the rule.

           The '~' is not a loosening for its own sake: it is what the catalog
           ALREADY stores and what the map already prints on the steepest
           marker. Five of the six seeded climbs carry values like "~20%",
           meaning "about 20%", which is honest for a pitch nobody has surveyed.

           Refusing it made editing those climbs impossible: the editor loads
           the stored steep marker, carries its pct into the hidden field, and
           any submission that touched the geometry was refused as
           `invalid_geometry` — the recurring "it just sends me back to the
           first page" report (owner, 2026-08-03). A validator that rejects the
           application's own data is the bug, not the data. */
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
     * The rider-placed steepest point: where the wall actually is.
     *
     * Deliberately its own attribute rather than an edit to `steep`. Ours is the
     * steepest sustained 100 m the model can see, measured identically on every
     * climb, which is what makes it comparable and sortable. If riders could
     * overwrite it, the published field would mean something different on every
     * climb depending on whether anyone happened to edit it — which is exactly
     * how the catalogue ended up publishing four definitions of "max gradient"
     * under one name (climb-elevation.md §1a, §5a).
     *
     * `pct` is optional: a rider may know *where* the wall is without knowing
     * how steep, and forcing a number would invite invented ones. `note` gives
     * them somewhere to say what they do know ("the hairpin after the chapel").
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
        // Bounded like any other free text reaching published attributes: this
        // is a landmark, not a paragraph.
        $note = mb_substr(trim((string) $noteRaw), 0, 120);

        return ['at' => [$lat, $lng], 'pct' => $pct, 'note' => $note];
    }

    /**
     * Validates a gradient string against the same shape `steep.pct` accepts,
     * so the average and the maximum cannot disagree about what a gradient
     * looks like. Deliberately the same expression rather than a stricter one:
     * the average is derived and will not carry a '~', but a validator that is
     * tighter than the data the catalog already holds is how the editor became
     * unusable on five of six seeded climbs once before.
     */
    private static function gradientText(string $raw): string
    {
        $v = trim($raw);
        if ('' !== $v && 1 !== preg_match('/^~?\d{1,2}(\.\d{1,2})?%?$/', $v)) {
            throw new \InvalidArgumentException('avg must look like a gradient, e.g. "5.7%"');
        }

        return $v;
    }
}
