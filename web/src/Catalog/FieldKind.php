<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

/**
 * The input kind of a catalog field: maps onto a Symfony form type in
 * {@see \App\Form\ImproveType} (text → TextType, select → ChoiceType,
 * textarea → TextareaType).
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
     * The two-level outbound-links editor (catalog-data-model.md §7 `links`).
     *
     * A kind of its own rather than a reuse: every other kind here renders ONE
     * control for ONE scalar, and this is a nested repeatable over free text -
     * a list of destinations, each holding a list of per-locale urls. It
     * travels as a single hidden JSON field, the way the climb `route` and the
     * segment shapes already do, so the nesting never reaches PHP's array
     * parsing where something other than {@see Import\OutboundLinks}
     * would decide what a malformed post means.
     */
    case Links = 'links';
}
