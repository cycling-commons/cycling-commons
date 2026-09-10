<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Settings;

/**
 * Read side of runtime configuration.
 *
 * @see docs/specs/system-configuration.md §3
 *
 * @api
 */
interface SettingsProviderInterface
{
    /**
     * Effective int value: stored override if legal, else YAML default.
     *
     * @throws \InvalidArgumentException unknown key
     */
    public function get(string $key): int;

    /**
     * Effective string value.
     *
     * @throws \InvalidArgumentException unknown or numeric key
     */
    public function getString(string $key): string;
}
