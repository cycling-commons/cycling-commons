<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Service;

/**
 * @api
 */
final readonly class ContributionReceipt
{
    public function __construct(
        public string $reference,
        public string $kind,
        public bool $persisted,
        public \DateTimeImmutable $submittedAt,
        public ?int $submissionId = null,
        /** True when the change was applied at once: a curator's own edit (moderation-and-contribution.md §1.6). */
        public bool $applied = false,
        /** True when the curator also ticked "Mark it confirmed" on an applied edit (moderation-and-contribution.md §1.6). */
        public bool $confirmed = false,
    ) {
    }
}
