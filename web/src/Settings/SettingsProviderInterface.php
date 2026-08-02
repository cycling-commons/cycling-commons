<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Settings;

/**
 * The read side of the runtime configuration, and the only thing a consumer
 * should depend on. Services used to take their thresholds as constructor
 * scalars bound from `%parameters%`, which meant every tuning change was a
 * deploy; they now take this and read through it, so an admin edit takes effect
 * on the next request without a container rebuild.
 *
 * Numeric settings read through get(); the text ones through getString().
 * Two types, deliberately — see SettingDefinition for why.
 *
 * @see docs/specs/system-configuration.md §3
 *
 * @api Injected into CuratedReadiness, RouteQueue, RouteModerationService,
 *      RouteCommunityService and RetentionService.
 */
interface SettingsProviderInterface
{
    /**
     * The effective value of a setting: the admin's override if there is a
     * usable one, otherwise the YAML default.
     *
     * @param string $key a SettingsRegistry key constant
     *
     * @throws \InvalidArgumentException if the key is not in the registry —
     *                                   a typo must fail loudly, never read as a silent zero
     */
    public function get(string $key): int;

    /**
     * The same, for a text setting.
     *
     * @throws \InvalidArgumentException if the key is unknown, or is numeric
     *                                   — reading a threshold as text is a bug, not a coercion
     */
    public function getString(string $key): string;
}
