<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog\Import;

use App\Catalog\CatalogFormRegistry;
use App\Catalog\ItemType;

/**
 * Import attribute keys: unknown keys are an error.
 *
 * @see docs/specs/catalog-data-model.md §7
 *
 * @api
 */
final class AttributeVocabulary
{
    // photos (gallery) beside photo. links: docs/specs/catalog-data-model.md §7
    private const array COMMON = ['t', 'town', 'web', 'c', 'sim', 'r', 'desc', 'descTr', 'photo', 'photos', 'links'];

    private const array EXTRAS = [
        // segment: add-wizard endpoints. waysSpanned: OSM ways a run-prefilled stretch covers.
        'A' => ['cls', 'photoFile', 'photoCredit', 'photoUser', 'photoLicense', 'segment', 'waysSpanned'],
        // attribution not source (provenance owns source). Measured keys: docs/specs/climb-elevation.md §4
        'N' => ['headline', 'cur', 'sq', 'tr', 'record', 'attribution', 'route', 'grad',
            'steep', 'steepPoint', 'lineGrad', 'binM', 'length', 'gain', 'demSource',
            'steepWindowM',
            'footEle', 'summitEle'],
        'D' => [],
    ];

    public function __construct(private readonly CatalogFormRegistry $registry)
    {
    }

    /** @return list<string> */
    public function allowedKeys(ItemType $type): array
    {
        $registryKeys = array_map(
            static fn ($field): string => $field->name,
            $this->registry->for($type)->all(),
        );

        return array_values(array_unique([
            ...$registryKeys,
            ...self::COMMON,
            ...(self::EXTRAS[$type->letter()] ?? []),
        ]));
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @throws \InvalidArgumentException listing every unknown key
     */
    public function assertValid(ItemType $type, array $attributes): void
    {
        $unknown = array_diff(array_keys($attributes), $this->allowedKeys($type));
        if ([] !== $unknown) {
            throw new \InvalidArgumentException(sprintf('Unknown attribute key(s) for letter %s: %s', $type->letter(), implode(', ', $unknown)));
        }
    }
}
