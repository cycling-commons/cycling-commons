<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Onboarding;

/**
 * DBAL access to onboarded countries, their plan rows and their neighbours.
 *
 * @api
 */
final class Countries
{
    /** @throws \InvalidArgumentException */
    public static function code(string $raw): string
    {
        $cc = strtoupper(trim($raw));
        if (1 !== preg_match('/^[A-Z]{2}$/', $cc)) {
            throw new \InvalidArgumentException(sprintf('Not an ISO 3166-1 alpha-2 code: "%s".', $raw));
        }

        return $cc;
    }
}
