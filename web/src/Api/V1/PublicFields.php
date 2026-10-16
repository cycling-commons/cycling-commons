<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Api\V1;

use App\Catalog\CatalogField;
use App\Catalog\CatalogFormRegistry;
use App\Catalog\FieldKind;
use App\Catalog\ItemType;
use App\Catalog\PlaceKind;
use App\Catalog\ServiceKind;

/**
 * Which of an item's attributes the API serves, and which a partner app may
 * send (docs/specs/public-api.md §2.2, §8).
 *
 * Served: the fields of the letter's own form whose value is chosen from a
 * list (select, multi-select), a measured number nobody typed (a climb's
 * gradient), or the place's own website. Never served: text a person wrote
 * (notes, what you saw, opening hours on C, tools), because free text can
 * hold personal data and the API carries none
 * (docs/specs/public-api-personal-data-boundary.md). An allowlist, so a new
 * text field stays out until somebody decides otherwise.
 *
 * Sent: every field the letter's form offers a rider, except the measured
 * ones (the server measures them) and the outbound-links editor.
 *
 * @api
 */
final readonly class PublicFields
{
    /** The kinds a value is picked from, never typed. */
    private const array PICKED = [FieldKind::Select, FieldKind::MultiSelect];

    /** Where a place keeps its own kind (PlaceKind, ServiceKind). */
    public const string KIND_SERVICE = 'serviceKind';
    public const string KIND_TYPE = 'type';

    /** Form fields that carry the name, which the API sends as `name`. */
    private const array NAME_FIELDS = ['name', 'rideName'];

    public function __construct(private CatalogFormRegistry $forms)
    {
    }

    /**
     * Every field of a letter's form, as the catalogue publishes it.
     *
     * @return list<array{key: string, label: string, type: string, values: list<string>, labels: array<string, string>, served: bool, accepted: bool}>
     */
    public function describe(ItemType $type): array
    {
        $out = [];
        foreach ($this->fields($type) as $field) {
            // The name travels as the feature's own `name`, never as a field.
            if (\in_array($field->name, self::NAME_FIELDS, true)) {
                continue;
            }
            $out[] = [
                'key' => $field->name,
                'label' => $field->label,
                'type' => $field->derived ? 'measured' : $field->kind->value,
                'values' => $field->choices,
                'labels' => $field->choiceLabels,
                'served' => self::served($field),
                'accepted' => self::accepted($field),
            ];
        }

        return $out;
    }

    /**
     * The served attributes of a stored row, in the form's order.
     *
     * @param array<string, mixed> $attributes
     *
     * @return array<string, mixed>
     */
    public function servedOf(ItemType $type, array $attributes): array
    {
        $out = [];
        foreach ($this->fields($type) as $field) {
            if (!self::served($field) || !\array_key_exists($field->name, $attributes)) {
                continue;
            }
            $value = $attributes[$field->name];
            if (null === $value || '' === $value || [] === $value) {
                continue;
            }
            if (FieldKind::Url === $field->kind && !(\is_string($value) && 1 === preg_match('~^https?://~i', $value))) {
                continue;
            }
            // A pick list serves only its own values: an old or imported row
            // holding typed text under a pick-list key keeps it to itself.
            if (FieldKind::Select === $field->kind && !(\is_string($value) && \in_array($value, $field->choices, true))) {
                continue;
            }
            if (FieldKind::MultiSelect === $field->kind) {
                $value = \is_array($value) ? array_values(array_filter($value, static fn (mixed $v): bool => \is_string($v) && \in_array($v, $field->choices, true))) : [];
                if ([] === $value) {
                    continue;
                }
            }
            if ($field->derived && !\is_int($value) && !\is_float($value) && !(\is_string($value) && is_numeric($value))) {
                continue;
            }
            $out[$field->name] = $value;
        }

        return $out;
    }

    /**
     * The fields a contribution may carry for this letter, by key.
     *
     * @return array<string, CatalogField>
     */
    public function acceptedFields(ItemType $type): array
    {
        $out = [];
        foreach ($this->fields($type) as $field) {
            if (self::accepted($field) && !\in_array($field->name, self::NAME_FIELDS, true)) {
                $out[$field->name] = $field;
            }
        }

        return $out;
    }

    /** The attribute that holds a letter's kind: `serviceKind` for D, `type` where PlaceKind keys it, else null. */
    public static function kindField(ItemType $type): ?string
    {
        return match ($type) {
            ItemType::BikeServices => self::KIND_SERVICE,
            ItemType::WhereToSleep, ItemType::Shelter, ItemType::ScenicViews, ItemType::HistoryCulture => self::KIND_TYPE,
            default => null,
        };
    }

    /**
     * An item's kind in the read API (public-api.md section 2.2): the stored one (kindField()), else the one its
     * type label `t` names, by the rule the catalogue import uses (ServiceKind::fromLegacyLabel, PlaceKind::fromLabel),
     * else null. A consumer draws its icon from it: a repair station without a stored kind drew the shop icon.
     *
     * @param array<string, mixed> $attributes
     */
    public static function kindOf(ItemType $type, array $attributes): ?string
    {
        $field = self::kindField($type);
        if (null === $field) {
            return null;
        }
        $stored = $attributes[$field] ?? null;
        if (\is_string($stored) && '' !== $stored) {
            return $stored;
        }
        $label = \is_string($attributes['t'] ?? null) ? $attributes['t'] : null;

        return self::KIND_SERVICE === $field ? ServiceKind::fromLegacyLabel($label)?->value : PlaceKind::fromLabel($type->letter(), $label);
    }

    private static function served(CatalogField $field): bool
    {
        if (!$field->display && !$field->derived) {
            return false;
        }

        return $field->derived
            || \in_array($field->kind, self::PICKED, true)
            || (FieldKind::Url === $field->kind && 'web' === $field->name);
    }

    private static function accepted(CatalogField $field): bool
    {
        return !$field->derived && FieldKind::Links !== $field->kind;
    }

    /** @return list<CatalogField> */
    private function fields(ItemType $type): array
    {
        return $this->forms->for($type)->all();
    }
}
