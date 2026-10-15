<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Map;

use App\Api\V1\CategoryTable;
use App\Catalog\BikeType;

/**
 * A preset as the map and the settings page send it, checked for shape and
 * normalised before any budget is spent (docs/specs/map-presets.md section
 * 1 and 5). An unknown key, mode, layer, overlay, season, bike or facet
 * group is refused; a facet value the map no longer has is dropped, so an
 * old preset stays usable.
 *
 * @api
 */
final readonly class PresetInput
{
    public const int MAX_NAME = 40;
    public const array MODES = ['curated', 'confirmed', 'all'];
    /** The tools a preset can be used for (map-presets.md section 1): the map at open, Plan a ride, Check my ride, Scout review. */
    public const array CONTEXTS = ['map', 'plan', 'ride', 'scout'];
    public const array OVERLAYS = ['surface', 'routes'];
    public const array SEASONS = ['spring', 'summer', 'autumn', 'winter'];
    /** The key's surface classes and route types (owner 2026-10-15: "add surface and routes to the presets"); absent means every one on. */
    public const array SURFACES = ['paved', 'gravel', 'pave', 'dirt', 'rock', 'unverified'];
    public const array ROUTES = ['national', 'regional', 'mtb', 'knoop'];
    /** The facet chip groups of the Layers panel and their values, as the template's data-v (map-and-search.md section 4.3). */
    public const array FACETS = [
        'sqf' => ['Smooth', 'Good', 'Worn', 'Rough', 'Broken / loose'],
        'trf' => ['Traffic-free', 'Quiet', 'Moderate', 'Busy'],
        'effortf' => ['Steady', 'Challenging', 'Tough', 'Very steep'],
        'accessf' => ['Step-free access', 'Handbike-friendly', 'Wheelchair-accessible'],
    ];

    /**
     * @param array{mode: string, layers: list<string>, overlays: list<string>, seasons: list<string>, bikes: list<string>, facets: array<string, list<string>>, surfaces?: list<string>, routes?: list<string>} $settings
     */
    public function __construct(
        public ?int $id,
        public string $name,
        /** @var list<string>|null the tools this preset is used for; null leaves them as they are */
        public ?array $uses,
        public array $settings,
    ) {
    }

    /**
     * The twelve catalogue keys the Layers panel lists.
     *
     * @return list<string>
     */
    public static function layerKeys(): array
    {
        return array_map(static fn (array $c): string => $c['key'], CategoryTable::CATEGORIES);
    }

    /** The body, or null when any field is not what the map sends. */
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
        $uses = null;
        if (\array_key_exists('uses', $payload)) {
            $uses = self::subset($payload['uses'], self::CONTEXTS);
            if (null === $uses) {
                return null;
            }
        }
        $settings = self::settings($payload['settings'] ?? null);
        if (null === $settings) {
            return null;
        }

        return new self($id, $name, $uses, $settings);
    }

    /**
     * The settings, normalised: lists sorted, every key present; null on any shape the map does not send.
     *
     * @return array{mode: string, layers: list<string>, overlays: list<string>, seasons: list<string>, bikes: list<string>, facets: array<string, list<string>>, surfaces?: list<string>, routes?: list<string>}|null
     */
    public static function settings(mixed $raw): ?array
    {
        if (!\is_array($raw) || array_is_list($raw)) {
            return null;
        }
        if ([] !== array_diff(array_keys($raw), ['mode', 'layers', 'overlays', 'seasons', 'bikes', 'facets', 'surfaces', 'routes'])) {
            return null;
        }
        $mode = $raw['mode'] ?? 'all';
        if (!\is_string($mode) || !\in_array($mode, self::MODES, true)) {
            return null;
        }
        $layers = self::subset($raw['layers'] ?? [], self::layerKeys());
        $overlays = self::subset($raw['overlays'] ?? [], self::OVERLAYS);
        $seasons = self::subset($raw['seasons'] ?? [], self::SEASONS);
        $bikes = self::subset($raw['bikes'] ?? [], array_map(static fn (BikeType $b): string => $b->value, BikeType::cases()));
        if (null === $layers || null === $overlays || null === $seasons || null === $bikes) {
            return null;
        }
        $facetsRaw = $raw['facets'] ?? [];
        if (!\is_array($facetsRaw) || ([] !== $facetsRaw && array_is_list($facetsRaw))) {
            return null;
        }
        $facets = [];
        foreach ($facetsRaw as $group => $values) {
            if (!\is_string($group) || !isset(self::FACETS[$group]) || !\is_array($values)) {
                return null;
            }
            foreach ($values as $v) {
                if (!\is_string($v)) {
                    return null;
                }
            }
            // A value the map no longer has is dropped, never refused.
            $kept = array_values(array_intersect(self::FACETS[$group], $values));
            $facets[$group] = $kept;
        }
        ksort($facets);

        $out = ['mode' => $mode, 'layers' => $layers, 'overlays' => $overlays, 'seasons' => $seasons, 'bikes' => $bikes, 'facets' => $facets];
        foreach (['surfaces' => self::SURFACES, 'routes' => self::ROUTES] as $key => $allowed) {
            if (\array_key_exists($key, $raw)) {
                $list = self::subset($raw[$key], $allowed);
                if (null === $list) {
                    return null;
                }
                $out[$key] = $list;
            }
        }

        return $out;
    }

    /**
     * A list of strings drawn from the allowed set, in the set's order, each once; null for anything else.
     *
     * @param list<string> $allowed
     *
     * @return list<string>|null
     */
    public static function subset(mixed $raw, array $allowed): ?array
    {
        if (!\is_array($raw) || !array_is_list($raw)) {
            return null;
        }
        foreach ($raw as $v) {
            if (!\is_string($v) || !\in_array($v, $allowed, true)) {
                return null;
            }
        }

        return array_values(array_intersect($allowed, $raw));
    }
}
