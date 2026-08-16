<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media\MessageHandler;

use App\Media\Message\ScanAndReleaseUpload;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Task-4 landing site (media plan): quarantine -> scan -> physical release.
 *
 * The release pipeline is NOT built yet - this handler exists so the async
 * tier (task 1) is complete and provable end to end before the media flow
 * moves onto it. Nothing dispatches ScanAndReleaseUpload today: the upload
 * endpoint is still synchronous, so this handler firing in production would
 * itself be a bug worth a warning.
 *
 * When task 4 lands here, the contract is already decided (plan + spec):
 * no-op unless the row is still pending_scan AND the quarantine object still
 * exists (redelivery after success must be harmless); infected -> delete +
 * reject + tell the rider; scanner error -> THROW so Messenger retries and
 * the object stays quarantined; clean -> decode, write derivatives to the
 * public bucket, original to the private one, delete quarantine, mark ready.
 */
#[AsMessageHandler]
final readonly class ScanAndReleaseUploadHandler
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function __invoke(ScanAndReleaseUpload $message): void
    {
        $this->logger->warning('ScanAndReleaseUpload received but the release pipeline (media plan task 4) is not built - upload {id} was NOT processed.', [
            'id' => $message->mediaId,
        ]);
    }
}
