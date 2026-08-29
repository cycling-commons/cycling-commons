<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Support;

/**
 * What a person can report.
 *
 * DSA Article 16 asks for a notice-and-action mechanism **any** person can use,
 * with no account, for **any** content they believe is illegal. Photos already
 * had one (`/photo/{uuid}/report`); everything else a rider can write had
 * nothing, which is the gap this closes.
 *
 * A closed enum rather than a free-text "what is this about", because the
 * report has to route to the right curator desk and name the right thing in the
 * statement of reasons. An unknown value is a 404, not a guess.
 *
 * **Photos are deliberately absent.** They keep their own route, which does
 * more than this one: an intimate-imagery report there hides the photo before
 * any person has seen it. Folding that into a generic form would either lose
 * the auto-withhold or apply it to things it makes no sense for.
 *
 * @see docs/specs/content-reports.md §1
 *
 * @api
 */
enum ReportTarget: string
{
    case Route = 'route';
    case Item = 'item';
    case RegionText = 'region';
    case DisplayName = 'rider';
    case Message = 'message';

    /** The catalogue key naming this kind of thing to a reader. */
    public function label(): string
    {
        return 'report.target.'.$this->value;
    }

    /**
     * Is this id shaped like one of ours?
     *
     * Cheap sanity only, deliberately not a lookup: a GET on the form must not
     * become an oracle for whether a given id exists.
     */
    public function acceptsId(string $id): bool
    {
        return match ($this) {
            self::DisplayName => 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id),
            default => 1 === preg_match('/^[1-9][0-9]{0,9}$/', $id),
        };
    }

    public static function tryFromPath(string $value): ?self
    {
        return self::tryFrom($value);
    }
}
