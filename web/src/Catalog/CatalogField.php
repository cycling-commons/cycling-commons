<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

/**
 * One editable field in a type's form (a row in the "Fix details" or
 * "Add missing" pane). Immutable fixture data ported from edit-items.js.
 *
 * @api Read by the improve form and template.
 */
final readonly class CatalogField
{
    /** @param list<string> $choices non-empty for {@see FieldKind::Select}, empty otherwise */
    private function __construct(
        public string $name,
        public string $label,
        public FieldKind $kind,
        public array $choices = [],
        public string $placeholder = '',
        public string $default = '',
        public bool $required = false,
        public int $maxLength = 500,
    ) {
    }

    public static function text(string $name, string $label, string $default = '', string $placeholder = ''): self
    {
        return new self($name, $label, FieldKind::Text, default: $default, placeholder: $placeholder);
    }

    public static function textarea(string $name, string $label, string $placeholder = ''): self
    {
        return new self($name, $label, FieldKind::Textarea, placeholder: $placeholder, maxLength: 2000);
    }

    /**
     * A text-like field whose value must be a real http(s) URL. Constrained by
     * {@see \App\Form\CatalogFieldConstraints} to http/https so a non-http
     * scheme (javascript:, data:, …) can never persist and reach the map's
     * `<a href>` — security review 2026-07-07 (critical #3).
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
     * A select field whose stored value is a list<string> over $choices
     * (P2-D2) — e.g. a route's suitable bike types — rather than a single
     * scalar.
     *
     * @param list<string> $choices
     */
    public static function multiselect(string $name, string $label, array $choices): self
    {
        return new self($name, $label, FieldKind::MultiSelect, choices: $choices);
    }
}
