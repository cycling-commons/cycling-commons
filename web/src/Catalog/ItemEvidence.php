<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

/**
 * One item's place on the two axes (docs/specs/data-provider-hierarchy.md
 * §6.7): who keeps the record, and how far up the evidence ladder it stands,
 * with the receipt that produced the rung.
 *
 * The map draws `custody` as the border and `badge` as the `?`. The public
 * API publishes `grade` first and the receipt fields behind it, so a grade
 * can never be a trust-me.
 *
 * @api
 */
final readonly class ItemEvidence
{
    /**
     * @param 'claimed'|'attested'|'minimum'|'high' $grade
     * @param 'riders'|'curator'|null               $verifiedBy
     */
    public function __construct(
        public CustodyTier $custody,
        public int $rung,
        public string $grade,
        public bool $badge,
        public int $confirmations,
        public ?\DateTimeImmutable $lastConfirmed,
        public ?\DateTimeImmutable $lastSeenUpstream,
        public ?string $verifiedBy,
    ) {
    }
}
