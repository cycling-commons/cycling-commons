<?php

// SPDX-License-Identifier: AGPL-3.0-only

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
        /** True when the curator ticked "Mark it confirmed" (moderation-and-contribution.md §1.6), with an applied edit or alone. */
        public bool $confirmed = false,
        /** The rider's waiting new-place submission a curator's correction or confirmation went to; it still waits for the decision. */
        public ?int $waitingOn = null,
    ) {
    }
}
