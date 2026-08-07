<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Routing;

/**
 * Localized route-prefix map: English is served clean (no prefix), the other
 * enabled locales carry a `/xx` path prefix (`/regions`, `/fr/regions`, …).
 *
 * Reference it as a class-level `#[Route(LocalePrefix::PATHS)]` on controllers
 * whose every action is localized; Symfony then generates one route per locale
 * and sets `_locale` from the matched path automatically. Keep this in sync
 * with `framework.enabled_locales` (config/packages/translation.yaml).
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
