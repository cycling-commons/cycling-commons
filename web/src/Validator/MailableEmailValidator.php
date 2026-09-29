<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Exception\InvalidArgumentException;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * @api
 */
final class MailableEmailValidator extends ConstraintValidator
{
    #[\Override]
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof MailableEmail) {
            throw new UnexpectedValueException($constraint, MailableEmail::class);
        }
        if (null === $value || '' === $value) {
            return;
        }
        if (!\is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        // The mailer's own check is private, so ask it the only way it answers.
        try {
            new Address($value);
        } catch (RfcComplianceException|InvalidArgumentException) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
