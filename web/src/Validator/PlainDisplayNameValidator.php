<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * @api Resolved by the validator for #[PlainDisplayName].
 */
final class PlainDisplayNameValidator extends ConstraintValidator
{
    /**
     * Any Unicode space separator that is NOT a plain U+0020 (the
     * double-negation class `[^\P{Z}\x20]` reads as "in \p{Z} and not a
     * space": non-breaking, ideographic, en/em quads, narrow no-break …),
     * or ASCII control whitespace.
     */
    private const string EXOTIC_WHITESPACE = '/[^\P{Z}\x20]|[\t\n\v\f\r]/u';

    /** Two or more consecutive spaces, or whitespace at either end. */
    private const string UNTIDY_SPACING = '/\x20{2,}|^\s|\s$/u';

    #[\Override]
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof PlainDisplayName) {
            throw new UnexpectedValueException($constraint, PlainDisplayName::class);
        }
        if (null === $value || '' === $value) {
            // NotBlank's job, not ours — see the constraint's class doc.
            return;
        }
        if (!\is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        // At most one violation per name: a rider with three doubled spaces
        // needs to be told once, not three times.
        if (1 === preg_match(self::EXOTIC_WHITESPACE, $value)
            || 1 === preg_match(self::UNTIDY_SPACING, $value)
        ) {
            $this->context->buildViolation($constraint->spacingMessage)->addViolation();

            return;
        }

        // Fullwidth letters, ligatures and other compatibility forms look like
        // ordinary text but are not, so a name using them reads as a copy of
        // somebody else's. NFKC is the normalization that folds them; a value
        // already in that form is left alone.
        if (!\Normalizer::isNormalized($value, \Normalizer::FORM_KC)) {
            $this->context->buildViolation($constraint->compatibilityMessage)->addViolation();
        }
    }
}
