<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Contribution;

use App\Catalog\Import\AttributeVocabulary;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * @api Resolved by the validator for #[ValidAttributes].
 */
final class ValidAttributesValidator extends ConstraintValidator
{
    public function __construct(private readonly AttributeVocabulary $vocabulary)
    {
    }

    #[\Override]
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$value instanceof SubmissionDraft) {
            throw new UnexpectedValueException($value, SubmissionDraft::class);
        }
        \assert($constraint instanceof ValidAttributes);

        try {
            $this->vocabulary->assertValid($value->type, $value->attributes);
        } catch (\InvalidArgumentException $e) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ detail }}', $e->getMessage())
                ->atPath('attributes')
                ->addViolation();
        }
    }
}
