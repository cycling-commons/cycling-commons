<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

/**
 * The input kind of a catalog field — maps onto a Symfony form type in
 * {@see \App\Form\ImproveType} (text → TextType, select → ChoiceType,
 * textarea → TextareaType).
 */
enum FieldKind: string
{
    case Text = 'text';
    case Select = 'select';
    case Textarea = 'textarea';
    case Url = 'url';
    /** Same choice universe as {@see Select}, but the value is a list<string> (P2-D2). */
    case MultiSelect = 'multiselect';
}
