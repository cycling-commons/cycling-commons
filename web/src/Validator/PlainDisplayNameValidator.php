<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * @api
 */
final class PlainDisplayNameValidator extends ConstraintValidator
{
    /** Unicode Z separators other than U+0020, plus ASCII control whitespace. */
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
            return;
        }
        if (!\is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        if (1 === preg_match(self::EXOTIC_WHITESPACE, $value)
            || 1 === preg_match(self::UNTIDY_SPACING, $value)
        ) {
            $this->context->buildViolation($constraint->spacingMessage)->addViolation();

            return;
        }

        // NFKC folds fullwidth/ligature lookalikes; already-normalized names pass.
        if (!\Normalizer::isNormalized($value, \Normalizer::FORM_KC)) {
            $this->context->buildViolation($constraint->compatibilityMessage)->addViolation();
        }
    }
}
