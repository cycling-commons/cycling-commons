<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Account;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * The viewer's date and time formats, for code that writes a date outside
 * Twig. The templates use `cc_date`, `cc_datetime` and `cc_month`
 * ({@see \App\Twig\DateDisplayExtension}) and the browser uses
 * `js/cc-dates.js`; all three read the preference through this one answer, so
 * a rider who picks 01-08-2026 reads 01-08-2026 everywhere.
 *
 * A null pattern means "the locale decides", which is what Auto and Long are.
 *
 * @see docs/specs/account-and-auth.md §9
 *
 * @api
 */
final readonly class DatePreference
{
    public function __construct(private Security $security)
    {
    }

    public function dateFormat(): DateFormat
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user->getDateFormat() : DateFormat::Auto;
    }

    public function timeFormat(): TimeFormat
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user->getTimeFormat() : TimeFormat::Auto;
    }

    /** ICU date pattern, or null to leave it to the locale. */
    public function datePattern(): ?string
    {
        return $this->dateFormat()->pattern();
    }

    /** ICU time pattern, or null to leave it to the locale. */
    public function timePattern(): ?string
    {
        return $this->timeFormat()->pattern();
    }

    /**
     * One ICU pattern for a date and a time together, or null when both sides
     * are left to the locale.
     */
    public function dateTimePattern(): ?string
    {
        $date = $this->datePattern();
        $time = $this->timePattern();
        if (null === $date && null === $time) {
            return null;
        }

        return trim(($date ?? 'yyyy-MM-dd').' '.($time ?? 'HH:mm'));
    }
}
