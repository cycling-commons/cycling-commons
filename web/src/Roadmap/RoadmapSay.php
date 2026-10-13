<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Roadmap;

use App\Settings\SettingsProviderInterface;
use App\Settings\SettingsRegistry;

/**
 * Whether riders have their say on the roadmap yet: votes, follows, the
 * Proposals tab and the proposal form. The system setting `roadmap.say_live`
 * (0 by default) switches it, so going live is a setting, not a deploy. The
 * roadmap list and the desk work either way.
 *
 * @see docs/specs/roadmap-and-changelog.md §8
 *
 * @api
 */
final readonly class RoadmapSay
{
    public function __construct(private SettingsProviderInterface $settings)
    {
    }

    public function isLive(): bool
    {
        return 1 === $this->settings->get(SettingsRegistry::ROADMAP_SAY_LIVE);
    }
}
