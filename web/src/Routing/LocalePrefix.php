<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Routing;

/**
 * Localized route prefixes: English unprefixed, others `/xx`.
 */
final class LocalePrefix
{
    /** @var array<string, string> */
    public const PATHS = [
        'en' => '',
        'fr' => '/fr',
        'nl' => '/nl',
        'de' => '/de',
        'es' => '/es',
    ];
}
