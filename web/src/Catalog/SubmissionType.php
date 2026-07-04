<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

/**
 * What a submission proposes (moderation spec §13 "TYPES relocation" —
 * this enum replaces ModerateController::TYPES). Intake produces
 * NewItem/Edit today; Hazard/Photo are queue-renderable, not yet collectable.
 *
 * @api Catalog domain enum.
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
