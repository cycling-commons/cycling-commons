<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * A display name must be spelled plainly: single U+0020 separators, no exotic
 * Unicode whitespace, no edge whitespace, and no Unicode compatibility forms.
 *
 * Display names are deliberately NOT unique (account-and-auth.md §9) — two
 * riders really can both be called John Doe, and the uuid is what tells them
 * apart. So this constraint is not about collisions. It is about the name
 * being shown honestly: it renders in photo credits, on public rider profiles
 * and in admin lists, and those surfaces should show what the rider typed
 * rather than something that merely looks like it.
 *
 * It covers the one class the existing guards miss. NoSuspiciousCharacters
 * (ICU Spoofchecker) rejects mixed-script confusables; the `\p{Cf}` regex
 * rejects invisibles; neither touches non-breaking spaces, doubled spaces or
 * fullwidth forms. The compatibility check in particular cannot be a regular
 * expression — it is NFKC idempotence — which is why this is a constraint
 * class rather than another Regex.
 *
 * Empty values pass: emptiness is NotBlank's job, and this constraint lives on
 * the entity, where write paths legitimately persist a User before a name
 * exists.
 *
 * @api Applied to User::$displayName.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class PlainDisplayName extends Constraint
{
    public string $spacingMessage = 'form.error_display_name_spacing';
    public string $compatibilityMessage = 'form.error_display_name_compatibility';
}
