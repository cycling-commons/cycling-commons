<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Support\MessageHandler;

use App\Support\Message\SendReportDigest;
use App\Support\ReportAlert;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Send the report alert's follow-up mail when its window closes, on the worker.
 *
 * @see docs/specs/contact-and-support.md §7
 *
 * @api
 */
#[AsMessageHandler]
final readonly class SendReportDigestHandler
{
    public function __construct(private ReportAlert $alert)
    {
    }

    /** @psalm-suppress UnusedParam the message carries nothing; its type is what routes it here */
    public function __invoke(SendReportDigest $message): void
    {
        $this->alert->sendDigest();
    }
}
