<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

/**
 * One editable field in a type's form.
 *
 * @see docs/specs/edit-items/README.md
 *
 * @api
 */
final readonly class CatalogField
{
    /**
     * @param list<string>          $choices      non-empty for {@see FieldKind::Select}, empty otherwise
     * @param array<string, string> $choiceLabels
     */
    private function __construct(
        public string $name,
        public string $label,
        public FieldKind $kind,
        public array $choices = [],
        public string $placeholder = '',
        public string $default = '',
        public bool $required = false,
        public int $maxLength = 500,
        public bool $display = true,
        /** Shown, never typed — opposite of `display: false` (editable, hidden). */
        public bool $derived = false,
        /** For a keyed select: stored value => label. Empty when the label IS the value. */
        public array $choiceLabels = [],
    ) {
    }

    /**
     * A select whose stored value is a machine key, not its label: `station`
     * shows as "Repair stand". `$choices` (the keys) stays the vocabulary the
     * intake validates against.
     *
     * @param array<string, string> $labelsByValue value => label
     */
    public static function selectKeyed(string $name, string $label, array $labelsByValue, bool $display = true): self
    {
        return new self($name, $label, FieldKind::Select, choices: array_keys($labelsByValue), display: $display, choiceLabels: $labelsByValue);
    }

    /** A value the app computes and displays; nobody types it. */
    public static function derivedText(string $name, string $label): self
    {
        return new self($name, $label, FieldKind::Text, derived: true);
    }

    public static function text(string $name, string $label, string $default = '', string $placeholder = '', bool $display = true): self
    {
        return new self($name, $label, FieldKind::Text, default: $default, placeholder: $placeholder, display: $display);
    }

    public static function textarea(string $name, string $label, string $placeholder = '', bool $display = true): self
    {
        return new self($name, $label, FieldKind::Textarea, placeholder: $placeholder, maxLength: 2000, display: $display);
    }

    /**
     * http(s) only — non-http schemes must never persist into a map `<a href>`.
     */
    public static function url(string $name, string $label, string $placeholder = ''): self
    {
        return new self($name, $label, FieldKind::Url, placeholder: $placeholder);
    }

    /** @param list<string> $choices */
    public static function select(string $name, string $label, array $choices, string $default = ''): self
    {
        return new self($name, $label, FieldKind::Select, choices: $choices, default: $default);
    }

    /**
     * Stored value is `list<string>` over `$choices`, not a scalar.
     *
     * @param list<string> $choices
     */
    public static function multiselect(string $name, string $label, array $choices): self
    {
        return new self($name, $label, FieldKind::MultiSelect, choices: $choices);
    }

    /**
     * Outbound-links editor. No placeholder/default: an item with no links stores no `links` key.
     *
     * @see docs/specs/catalog-data-model.md §7
     */
    public static function links(string $name, string $label): self
    {
        return new self($name, $label, FieldKind::Links);
    }
}
