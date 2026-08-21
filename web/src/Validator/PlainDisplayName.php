<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Plain spelling: U+0020 separators, no exotic whitespace, no NFKC-incompatible forms.
 *
 * @see docs/specs/account-and-auth.md §9
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class PlainDisplayName extends Constraint
{
    public string $spacingMessage = 'form.error_display_name_spacing';
    public string $compatibilityMessage = 'form.error_display_name_compatibility';
}
