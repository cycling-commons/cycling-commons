<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog\Import;

use App\Catalog\CatalogFormRegistry;
use App\Catalog\ItemType;

/**
 * The only attribute keys an imported item may carry: the catalog registry's
 * editable field names for the letter, plus shared display keys, plus a few
 * per-letter fixture extras. Unknown keys are an import ERROR — the "no key
 * exists unless declared" rule starts at the front door.
 *
 * @api Used by the catalog importer.
 */
final class AttributeVocabulary
{
    // 'photos' (plural) sits alongside singular 'photo': a gallery of 2+ images for
    // a pin (e.g. the demo climbs/scenic/history pins recovered in the C5 data-loss
    // fix) — map.js's photoList(f) already prefers f.photos over f.photo.
    private const array COMMON = ['t', 'town', 'web', 'c', 'sim', 'r', 'desc', 'descTr', 'photo', 'photos'];

    private const array EXTRAS = [
        'A' => ['cls', 'photoFile', 'photoCredit', 'photoUser', 'photoLicense'],
        // 'attribution' (not 'source'): the climbs export preserves the fixture's
        // citation as `attribution` since `source` is reserved for provenance
        // (App\Catalog\ItemSource) — human-approved deviation from the original
        // plan, already implemented on the export side (Task 3).
        'B' => ['headline', 'cur', 'sq', 'tr', 'record', 'attribution', 'route', 'grad', 'steep'],
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
