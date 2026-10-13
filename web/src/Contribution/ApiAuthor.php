<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Contribution;

/**
 * Who sent a contribution through the public API (docs/specs/public-api.md
 * §8): a registered partner app, and the opaque reference that app keeps for
 * its own user. We cannot resolve the reference to a person; it gives the
 * desk continuity ("this author's third contribution") and lets limits and
 * blocks hit one author instead of the whole app. The name is whatever the
 * author chose to share, or nothing.
 *
 * @api
 */
final readonly class ApiAuthor
{
    public function __construct(
        public int $appId,
        public string $appName,
        public string $ref,
        public ?string $name,
    ) {
    }

    /** The key a per-author limiter counts under. */
    public function limiterKey(): string
    {
        return 'app-'.$this->appId.'-'.hash('sha256', $this->ref);
    }
}
