<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Planner;

use App\Catalog\BikeType;

/**
 * A save body as the planner sends it, checked for shape and bounds and
 * normalised, before any budget is spent (docs/specs/route-planner.md
 * section 8). The checks that need the database, the line's length and the
 * ride cap, are {@see PlannedRides}'s.
 *
 * @api
 */
final readonly class RideInput
{
    public const int MAX_NAME = 80;
    public const int MIN_STOPS = 2;

    /** Owner decision, route-planner.md section 4.1. */
    public const int MAX_STOPS = 25;
    public const int MAX_STOP_ITEM = 80;
    public const int MAX_STOP_NAME = 160;
    public const int MIN_LINE_POINTS = 2;

    /** The same bound `/map/plan/along` puts on a line. */
    public const int MAX_LINE_POINTS = 5000;

    /** Ascent and descent are the rider's figures, stored as claimed within this bound. */
    public const int MAX_HEIGHT_M = 100_000;

    /** A sanity bound on the claimed distance; the measured line decides what passes. */
    public const int MAX_DISTANCE_M = 1_000_000;

    /**
     * @param list<array{lat: float, lng: float, item?: string, name?: string}> $stops
     * @param list<array{0: float, 1: float}>                                   $line  [lng, lat] pairs, GeoJSON order
     */
    public function __construct(
        public ?int $id,
        public string $name,
        public BikeType $bike,
        public array $stops,
        public array $line,
        public int $distanceM,
        public int $ascentM,
        public int $descentM,
    ) {
    }

    /** The body, or null when any field is not what the planner sends. */
    public static function parse(mixed $payload): ?self
    {
        if (!\is_array($payload)) {
            return null;
        }

        $id = $payload['id'] ?? null;
        if (null !== $id && (!\is_int($id) || $id < 1)) {
            return null;
        }

        $name = \is_string($payload['name'] ?? null) ? trim($payload['name']) : '';
        if ('' === $name || mb_strlen($name) > self::MAX_NAME) {
            return null;
        }

        $bike = BikeType::tryFrom(\is_string($payload['bike'] ?? null) ? $payload['bike'] : '');
        if (null === $bike) {
            return null;
        }

        $stops = self::stops($payload['stops'] ?? null);
        $line = self::line($payload['line'] ?? null);
        $distanceM = self::metres($payload['distanceM'] ?? null, self::MAX_DISTANCE_M);
        $ascentM = self::metres($payload['ascentM'] ?? null, self::MAX_HEIGHT_M);
        $descentM = self::metres($payload['descentM'] ?? null, self::MAX_HEIGHT_M);
        if (null === $stops || null === $line || null === $distanceM || null === $ascentM || null === $descentM) {
            return null;
        }

        return new self($id, $name, $bike, $stops, $line, $distanceM, $ascentM, $descentM);
    }

    /** The line as the geometry column takes it. */
    public function lineGeoJson(): string
    {
        return json_encode(['type' => 'LineString', 'coordinates' => $this->line], \JSON_THROW_ON_ERROR);
    }

    /**
     * Two to twenty-five stops, each a point on Earth with an optional place
     * reference and name; anything else on a stop is dropped.
     *
     * @return list<array{lat: float, lng: float, item?: string, name?: string}>|null
     */
    private static function stops(mixed $raw): ?array
    {
        if (!\is_array($raw) || !array_is_list($raw) || \count($raw) < self::MIN_STOPS || \count($raw) > self::MAX_STOPS) {
            return null;
        }
        $stops = [];
        foreach ($raw as $stop) {
            if (!\is_array($stop)) {
                return null;
            }
            $point = self::point([$stop['lng'] ?? null, $stop['lat'] ?? null]);
            if (null === $point) {
                return null;
            }
            $normalised = ['lat' => $point[1], 'lng' => $point[0]];
            foreach (['item' => self::MAX_STOP_ITEM, 'name' => self::MAX_STOP_NAME] as $key => $max) {
                if (!\array_key_exists($key, $stop) || null === $stop[$key]) {
                    continue;
                }
                if (!\is_string($stop[$key]) || mb_strlen($stop[$key]) > $max) {
                    return null;
                }
                $normalised[$key] = $stop[$key];
            }
            $stops[] = $normalised;
        }

        return $stops;
    }

    /**
     * A GeoJSON LineString of two to five thousand points on Earth.
     *
     * @return list<array{0: float, 1: float}>|null
     */
    private static function line(mixed $raw): ?array
    {
        if (!\is_array($raw) || 'LineString' !== ($raw['type'] ?? null) || !\is_array($raw['coordinates'] ?? null) || !array_is_list($raw['coordinates'])) {
            return null;
        }
        $pairs = $raw['coordinates'];
        if (\count($pairs) < self::MIN_LINE_POINTS || \count($pairs) > self::MAX_LINE_POINTS) {
            return null;
        }
        $line = [];
        foreach ($pairs as $pair) {
            $point = self::point($pair);
            if (null === $point) {
                return null;
            }
            $line[] = $point;
        }

        return $line;
    }

    /**
     * One [lng, lat] pair on Earth.
     *
     * @return array{0: float, 1: float}|null
     */
    private static function point(mixed $raw): ?array
    {
        if (!\is_array($raw) || !isset($raw[0], $raw[1]) || !is_numeric($raw[0]) || !is_numeric($raw[1])) {
            return null;
        }
        $lng = (float) $raw[0];
        $lat = (float) $raw[1];
        if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
            return null;
        }

        return [$lng, $lat];
    }

    /** A whole number of metres from zero to the bound; a fraction is rounded. */
    private static function metres(mixed $raw, int $max): ?int
    {
        if (!\is_int($raw) && !\is_float($raw)) {
            return null;
        }
        if (\is_float($raw) && !is_finite($raw)) {
            return null;
        }
        $metres = (int) round($raw);

        return $metres < 0 || $metres > $max ? null : $metres;
    }
}
