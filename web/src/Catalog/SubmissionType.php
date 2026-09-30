<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

/**
 * What a submission proposes. Intake produces NewItem/Edit and Text; Hazard/Photo are queue-renderable, not yet collectable.
 *
 * Text is a town card's or a region page's about text in one language
 * (moderation-and-contribution.md §3.1b): no catalog item and no letter, the
 * target named in the payload.
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
    case Text = 'text';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
