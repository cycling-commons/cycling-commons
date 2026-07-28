<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Settings;

/**
 * One runtime-editable editorial threshold: what it is called, what it falls
 * back to, the range an admin may move it within, and where it renders.
 *
 * The definition — not the database row — is the authority. A stored value that
 * no longer fits `min`/`max` (because a later release tightened the bound) is
 * ignored in favour of the default, so a legal-at-the-time number can never
 * outlive the rule that made it legal.
 *
 * @see docs/specs/system-configuration.md §2
 *
 * @api Built by SettingsRegistry; read by SystemSettings and the admin page.
 */
final readonly class SettingDefinition
{
    /**
     * @param string $key      also the container-parameter name the default comes from
     * @param string $group    a SettingsRegistry::GROUP_* constant; the admin page renders one card per group
     * @param string $labelKey translation key for the field label
     * @param string $helpKey  translation key for the sentence under it
     */
    public function __construct(
        public string $key,
        public int $default,
        public int $min,
        public int $max,
        public string $group,
        public string $labelKey,
        public string $helpKey,
    ) {
    }

    public function accepts(int $value): bool
    {
        return $value >= $this->min && $value <= $this->max;
    }
}
