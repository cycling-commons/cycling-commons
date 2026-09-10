<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

/**
 * What a submission proposes. Intake produces NewItem/Edit today; Hazard/Photo are queue-renderable, not yet collectable.
 *
 * @see docs/specs/moderation-and-contribution.md §3.1
 *
 * @api
 */
enum SubmissionType: string
{
    case NewItem = 'new';
    case Edit = 'edit';
    case Hazard = 'hazard';
    case Photo = 'photo';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
