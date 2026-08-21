<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

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
