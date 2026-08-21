<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

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
