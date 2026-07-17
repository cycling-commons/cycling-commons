<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;

/**
 * @api Seam for all contribution/moderation writes.
 */
interface ContributionStubInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function submit(string $kind, array $payload, ?User $by): ContributionReceipt;
}
