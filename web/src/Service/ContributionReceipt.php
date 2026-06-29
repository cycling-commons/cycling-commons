<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Service;

/**
 * @api Value object returned by ContributionStubInterface::submit().
 */
final readonly class ContributionReceipt
{
    public function __construct(
        public string $reference,
        public string $kind,
        public bool $persisted,
        public \DateTimeImmutable $submittedAt,
    ) {
    }
}
