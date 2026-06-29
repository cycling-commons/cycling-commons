<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Psr\Log\LoggerInterface;

/**
 * Temporary seam — no real domain persistence until the data-API spec.
 * Replace the body, keep the signature.
 *
 * @api Autowired via ContributionStubInterface; consumed by contribution/moderation tasks 2–5.
 */
final class ContributionStubService implements ContributionStubInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public function submit(string $kind, array $payload, ?User $by): ContributionReceipt
    {
        $reference = 'CC-'.strtoupper(bin2hex(random_bytes(6)));
        $persisted = false;
        $submittedAt = new \DateTimeImmutable();

        // TODO(data-api): persist via the data API (later spec)

        $this->logger->info('Contribution stub recorded', [
            'kind' => $kind,
            'reference' => $reference,
            'persisted' => false,
            'by' => $by?->getUserIdentifier(),
        ]);

        return new ContributionReceipt($reference, $kind, $persisted, $submittedAt);
    }
}
