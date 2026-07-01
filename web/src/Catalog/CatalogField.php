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
    ) {
    }

    public static function text(string $name, string $label, string $default = '', string $placeholder = ''): self
    {
        return new self($name, $label, FieldKind::Text, default: $default, placeholder: $placeholder);
    }

    public static function textarea(string $name, string $label, string $placeholder = ''): self
    {
        return new self($name, $label, FieldKind::Textarea, placeholder: $placeholder);
    }

    /** @param list<string> $choices */
    public static function select(string $name, string $label, array $choices, string $default = ''): self
    {
        return new self($name, $label, FieldKind::Select, choices: $choices, default: $default);
    }
}
