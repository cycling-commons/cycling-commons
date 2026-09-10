<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Contribution;

use Symfony\Component\Validator\Constraint;

/**
 * SubmissionDraft.attributes keys must belong to the letter's vocabulary
 * (docs/specs/catalog-data-model.md §7).
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ValidAttributes extends Constraint
{
    public string $message = 'contribute.error.unknown_attribute';

    #[\Override]
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
