<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

/**
 * Input kind of a catalog field; maps onto a Symfony form type in {@see \App\Form\ImproveType}.
 */
enum FieldKind: string
{
    case Text = 'text';
    case Select = 'select';
    case Textarea = 'textarea';
    case Url = 'url';
    /** Same choice universe as {@see Select}, but the value is a list<string>. */
    case MultiSelect = 'multiselect';
    /**
     * Two-level outbound-links editor; nested JSON, not a scalar control.
     *
     * @see docs/specs/catalog-data-model.md §7
     */
    case Links = 'links';
}
