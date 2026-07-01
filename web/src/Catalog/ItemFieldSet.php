<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

/**
 * A type's two field panes: "Fix details" ({@see self::$fields}) and
 * "Add missing" ({@see self::$addFields}). Both become Symfony form children.
 *
 * @api Read by the improve form and template.
 */
final readonly class ItemFieldSet
{
    /**
     * @param list<CatalogField> $fields    the "Fix details" pane (existing attributes)
     * @param list<CatalogField> $addFields the "Add missing" pane (type-specific extras)
     */
    public function __construct(
        public array $fields,
        public array $addFields = [],
    ) {
    }

    /** @return list<CatalogField> every field across both panes, in order */
    public function all(): array
    {
        return [...$this->fields, ...$this->addFields];
    }
}
