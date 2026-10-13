<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Roadmap;

/**
 * A roadmap proposal, or a desk change to an item or a proposal, that cannot
 * be taken as sent. Carries the catalogue key the form shows.
 *
 * @api
 */
final class RoadmapRefused extends \RuntimeException
{
    public function __construct(private readonly string $messageKey)
    {
        parent::__construct($messageKey);
    }

    public function messageKey(): string
    {
        return $this->messageKey;
    }
}
