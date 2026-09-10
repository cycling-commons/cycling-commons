<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Provider;

/**
 * Whether a licence obliges us to display an attribution notice.
 *
 * This is the one question that decides how a provider is credited, and it is
 * answered by the licence rather than by taste. A mandated text has to be
 * *displayed*, so it gets its own row with the required marker. Where nothing
 * is owed, naming the provider is decency rather than obligation, and a linked
 * name in a comma run names them. That is what lets `/credits` survive a
 * registry of hundreds without anyone deciding what is important.
 *
 * The set that owes something stays small by its nature. Codes not listed here
 * are treated as owing an attribution, which is the safe direction: a credit
 * shown that was not required costs a line, a credit omitted that was required
 * costs a licence breach.
 *
 * @see docs/specs/data-provider-hierarchy.md §9.3
 *
 * @api
 */
final class LicenceObligation
{
    /**
     * Codes that oblige nothing.
     *
     * Public-domain dedications and marks only. Everything else, including
     * every unrecognised code, owes a notice.
     *
     * @var list<string>
     */
    private const array OWES_NOTHING = [
        'cc0-1.0',
        'public-domain',
        'pdm-1.0',
    ];

    public static function requiresAttribution(string $licenceCode): bool
    {
        return !\in_array(strtolower(trim($licenceCode)), self::OWES_NOTHING, true);
    }
}
