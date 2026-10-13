<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Api\V1;

use App\Catalog\CustodyTier;
use App\Catalog\ItemType;

/**
 * `/v1/catalog` (docs/specs/public-api.md §2.2): what each letter is, the
 * kinds a place of that letter can be, and every field of its form: which
 * ones `/v1/items/{id}` serves, and which ones a partner app may send to
 * `/v1/contributions`, with the values a pick list allows. Built from the
 * same form registry the site's own wizard renders, so a consumer and a
 * rider are offered the same choices.
 *
 * English only, like every label in this API: the cacheable plane is never
 * locale-bound (CategoryTable).
 *
 * @api
 */
final readonly class PublicCatalog
{
    /**
     * Letters a partner app may add or edit through `/v1/contributions`: the
     * places a pin locates. A road surface is a stretch, a climb a measured
     * line, a route a whole track; those three keep their own editors on the
     * site.
     */
    public const array CONTRIBUTABLE = ['B', 'C', 'D', 'E', 'F', 'G', 'O', 'P', 'Q'];

    public function __construct(private PublicFields $fields)
    {
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        $letters = [];
        foreach (CategoryTable::CATEGORIES as $category) {
            $type = ItemType::fromLetter($category['letter']);
            if (null === $type) {
                continue;
            }
            $fields = $this->fields->describe($type);
            $kindField = PublicFields::kindField($type);
            $kinds = [];
            foreach ($fields as $field) {
                if ($field['key'] === $kindField) {
                    foreach ($field['values'] as $value) {
                        $kinds[] = ['key' => $value, 'label' => $field['labels'][$value] ?? $value];
                    }
                }
            }
            $letters[] = [
                'letter' => $category['letter'],
                'slug' => $type->value,
                'key' => $category['key'],
                'name' => $type->label(),
                'half' => $category['letter'] < 'N' ? 'practical' : 'experiential',
                'geometry' => $category['kind'],
                'kind_field' => $kindField,
                'kinds' => $kinds,
                'contributions' => \in_array($category['letter'], self::CONTRIBUTABLE, true),
                // (object) so a list with no keyed labels still encodes {}.
                'fields' => array_map(static fn (array $f): array => array_replace($f, ['labels' => (object) $f['labels']]), $fields),
            ];
        }

        return [
            'version' => '1',
            'letters' => $letters,
            'tiers' => ['community', 'curated'],
            'grades' => ['claimed', 'attested', 'minimum', 'high'],
            'custodies' => array_map(static fn (CustodyTier $c): string => $c->value, CustodyTier::cases()),
            'attribution' => CategoryTable::ATTRIBUTION,
        ];
    }
}
