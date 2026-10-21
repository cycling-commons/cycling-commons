<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Map;

use App\Api\V1\CategoryTable;
use App\Catalog\BikeType;
use App\Catalog\ItemType;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the settings page's preset editor draws (docs/specs/map-presets.md
 * section 4): the eleven layers with their label in the rider's language,
 * their colour and their drawn icon, the two overlays, the modes, the
 * seasons, the bikes and the facet chips, each with its label. One source,
 * so the editor and the map name every switch the same way.
 *
 * @api
 */
final class PresetCatalog
{
    /** The map's label key per layer key, as MapController hands the map its layer labels. */
    private const array LAYER_LABELS = [
        'surface' => 'item_type.road-surface.label', 'climbs' => 'item_type.climbs.label', 'water' => 'item_type.water-food.label',
        'toilets' => 'item_type.public-toilets.label', 'services' => 'item_type.bike-services.label', 'stays' => 'item_type.where-to-sleep.label',
        'hazards' => 'item_type.hazards.label', 'transit' => 'item_type.getting-there.label', 'shelter' => 'item_type.shelter.label',
        'scenic' => 'item_type.scenic-views.label', 'history' => 'item_type.history-culture.label', 'experience' => 'item_type.quality-rides.label',
    ];
    private const array SURFACE_LABELS = [
        'paved' => 'map.legend_paved', 'gravel' => 'map.legend_gravel', 'pave' => 'map.legend_cobbles',
        'dirt' => 'map.legend_dirt', 'rock' => 'map.legend_rock', 'unverified' => 'map.legend_unverified',
    ];
    private const array ROUTE_LABELS = [
        'national' => 'map.legend_route_national', 'regional' => 'map.legend_route_regional',
        'mtb' => 'map.legend_route_mtb', 'knoop' => 'map.legend_route_node',
    ];
    private const array FACET_LABELS = [
        'sqf' => ['label' => 'map.climb_surface', 'values' => ['Smooth' => 'map.surf_smooth', 'Good' => 'map.surf_good', 'Worn' => 'map.surf_worn', 'Rough' => 'map.surf_rough', 'Broken / loose' => 'map.surf_broken']],
        'trf' => ['label' => 'map.climb_traffic', 'values' => ['Traffic-free' => 'map.traffic_free', 'Quiet' => 'map.traffic_quiet', 'Moderate' => 'map.traffic_moderate', 'Busy' => 'map.traffic_busy']],
        'effortf' => ['label' => 'map.climb_effort', 'values' => ['Steady' => 'map.effort_steady', 'Challenging' => 'map.effort_challenging', 'Tough' => 'map.effort_tough', 'Very steep' => 'map.effort_very_steep']],
        'accessf' => ['label' => 'map.stay_access', 'values' => ['Step-free access' => 'map.access_step_free', 'Handbike-friendly' => 'map.access_handbike', 'Wheelchair-accessible' => 'map.access_wheelchair']],
    ];

    public function __construct(private readonly TranslatorInterface $t)
    {
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        $icons = ItemType::iconSet();
        $layers = [];
        foreach (CategoryTable::CATEGORIES as $c) {
            // Road surface is the Surfaces overlay below, not a layer row.
            if (!\in_array($c['key'], PresetInput::layerKeys(), true)) {
                continue;
            }
            $layers[] = ['key' => $c['key'], 'label' => $this->t->trans(self::LAYER_LABELS[$c['key']]), 'color' => $c['color'], 'svg' => $icons[$c['letter']]['svg']];
        }
        $facets = [];
        foreach (self::FACET_LABELS as $group => $def) {
            $values = [];
            foreach ($def['values'] as $value => $key) {
                $values[] = ['value' => $value, 'label' => $this->t->trans($key)];
            }
            $facets[] = ['group' => $group, 'label' => $this->t->trans($def['label']), 'values' => $values];
        }

        return [
            'max' => MapPresets::MAX_PRESETS,
            'layers' => $layers,
            'overlays' => [
                ['key' => 'surface', 'label' => $this->t->trans('map.surfaces'), 'color' => '#C8923A', 'svg' => $icons['A']['svg']],
                ['key' => 'routes', 'label' => $this->t->trans('map.route_network'), 'color' => '#7A4FCF', 'svg' => $icons['R']['svg']],
            ],
            // The key's switches (owner 2026-10-15), labelled as the key labels them.
            'surfaces' => array_map(fn (string $c): array => ['value' => $c, 'label' => $this->t->trans(self::SURFACE_LABELS[$c])], PresetInput::SURFACES),
            'routes' => array_map(fn (string $r): array => ['value' => $r, 'label' => $this->t->trans(self::ROUTE_LABELS[$r])], PresetInput::ROUTES),
            'modes' => ['curated' => $this->t->trans('map.curated'), 'confirmed' => $this->t->trans('map.confirmed'), 'all' => $this->t->trans('map.everything')],
            'seasons' => array_map(fn (string $s): array => ['value' => $s, 'label' => $this->t->trans('map.season_'.$s)], PresetInput::SEASONS),
            'bikes' => array_map(fn (BikeType $b): array => ['value' => $b->value, 'label' => $this->t->trans($b->labelKey())], BikeType::cases()),
            'facets' => $facets,
        ];
    }
}
