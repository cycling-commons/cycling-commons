<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;

/**
 * @api
 */
interface ContributionStubInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function submit(string $kind, array $payload, ?User $by): ContributionReceipt;
}
