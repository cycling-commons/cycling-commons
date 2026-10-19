<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Traffic;

/**
 * Felt-safety marks: how safe road pieces felt to a rider, for parts of the
 * day, day types and quarters of the last year (docs/specs/traffic-measurements.md
 * §10). One answer for one road or for several picked together (owner
 * 2026-10-19: "CTRL + click multiple road pieces together"), and for one time
 * or several (owner 2026-10-19: "make all fields checkboxes. I ride there all
 * of the time and it is always the same"); each road and time becomes its own
 * mark, at most MAX_MARKS.
 *
 * Unlike a traffic line, nothing in a mark comes from a device, so there is
 * no implausible-but-honest case to drop: every bad mark refuses the request.
 * A key that could carry a position or a time is `track_not_accepted`, as for
 * Scout, on the answer or on a road; anything else is `invalid`.
 *
 * @api
 */
final class SafetyMark
{
    private const array KEYS = ['v', 'roads', 'bands', 'dayTypes', 'quarters', 'felt'];
    private const array ROAD_KEYS = ['way', 'label', 'region'];
    public const array FELT = ['safe', 'unsafe'];
    /** The most roads one answer covers. */
    public const int MAX_ROADS = 50;
    /** The most marks one answer makes, roads times times: an hour's limit (rate_limiter.yaml road_safety_submit). */
    public const int MAX_MARKS = 200;
    /** Quarters before the latest one a mark may name: this quarter and the three before it, plus a zone east of Greenwich. */
    private const int QUARTERS_BACK = 4;

    /**
     * @param array<array-key, mixed> $raw the decoded request body
     *
     * @return non-empty-list<array{way: int, label: string, region: int|null, band: int, dayType: string, quarter: string, felt: string}> one mark per road and time, in the body's order
     *
     * @throws TrafficPayloadRefused
     */
    public static function accept(array $raw, \DateTimeImmutable $now): array
    {
        self::keysIn($raw, self::KEYS);
        $roads = $raw['roads'] ?? null;
        if (\is_array($roads)) {
            foreach ($roads as $road) {
                if (\is_array($road)) {
                    self::keysIn($road, self::ROAD_KEYS);
                }
            }
        }

        $bands = self::listOf($raw['bands'] ?? null, static fn (mixed $b): bool => \is_int($b) && isset(TrafficLine::BAND_SECONDS[$b]));
        $dayTypes = self::listOf($raw['dayTypes'] ?? null, static fn (mixed $d): bool => \in_array($d, TrafficLine::DAY_TYPES, true));
        $quarters = self::listOf($raw['quarters'] ?? null, static fn (mixed $q): bool => \is_string($q) && self::inYear($q, $now));
        if (3 !== ($raw['v'] ?? null)
            || !\is_array($roads) || !array_is_list($roads) || [] === $roads || \count($roads) > self::MAX_ROADS
            || null === $bands || null === $dayTypes || null === $quarters
            || \count($roads) * \count($bands) * \count($dayTypes) * \count($quarters) > self::MAX_MARKS
            || !\in_array($raw['felt'] ?? null, self::FELT, true)) {
            throw new TrafficPayloadRefused('invalid');
        }

        $marks = [];
        $seen = [];
        foreach ($roads as $road) {
            $way = \is_array($road) ? ($road['way'] ?? null) : null;
            $region = \is_array($road) ? ($road['region'] ?? null) : null;
            if (!\is_array($road) || !\is_int($way) || $way < 1 || isset($seen[$way])
                || (null !== $region && (!\is_int($region) || $region < 1))
                || !\in_array($road['label'] ?? null, TrafficLine::LABELS, true)) {
                throw new TrafficPayloadRefused('invalid');
            }
            $seen[$way] = true;
            foreach ($bands as $band) {
                foreach ($dayTypes as $dayType) {
                    foreach ($quarters as $quarter) {
                        $marks[] = [
                            'way' => $way, 'label' => (string) $road['label'], 'region' => $region, 'band' => (int) $band,
                            'dayType' => (string) $dayType, 'quarter' => (string) $quarter, 'felt' => (string) $raw['felt'],
                        ];
                    }
                }
            }
        }

        return $marks;
    }

    /**
     * A list of one to a few values, each once and each `$ok`; null otherwise.
     *
     * @param \Closure(mixed): bool $ok
     *
     * @return non-empty-list<mixed>|null
     */
    private static function listOf(mixed $raw, \Closure $ok): ?array
    {
        if (!\is_array($raw) || !array_is_list($raw) || [] === $raw || \count($raw) !== \count(array_unique($raw, \SORT_REGULAR))) {
            return null;
        }
        foreach ($raw as $v) {
            if (!$ok($v)) {
                return null;
            }
        }

        return $raw;
    }

    /**
     * @param array<array-key, mixed> $raw
     * @param list<string>            $allowed
     *
     * @throws TrafficPayloadRefused
     */
    private static function keysIn(array $raw, array $allowed): void
    {
        foreach (array_keys($raw) as $key) {
            if (\in_array($key, TrafficLine::TRACK_KEYS, true)) {
                throw new TrafficPayloadRefused('track_not_accepted');
            }
            if (!\in_array($key, $allowed, true)) {
                throw new TrafficPayloadRefused('invalid');
            }
        }
    }

    /** The quarter of tomorrow in UTC, or one of the QUARTERS_BACK before it. */
    private static function inYear(string $quarter, \DateTimeImmutable $now): bool
    {
        $q = [];
        if (1 !== preg_match('/^(\d{4})-Q([1-4])$/D', $quarter, $q)) {
            return false;
        }
        $tomorrow = $now->setTimezone(new \DateTimeZone('UTC'))->modify('+1 day');
        $latest = (int) $tomorrow->format('Y') * 4 + intdiv((int) $tomorrow->format('n') - 1, 3);
        $index = (int) $q[1] * 4 + (int) $q[2] - 1;

        return $index <= $latest && $index >= $latest - self::QUARTERS_BACK;
    }
}
