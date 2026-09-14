<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media\MessageHandler;

use App\Media\Commons\CommonsApi;
use App\Media\Commons\CommonsPhotoRepository;
use App\Media\Commons\CommonsPhotoState;
use App\Media\Commons\CommonsUnavailable;
use App\Media\MediaStorage;
use App\Media\Message\FetchCommonsPhoto;
use App\Media\PhotoProcessor;
use App\Media\PhotoRejected;
use App\Media\Scan\ScannerUnavailable;
use App\Media\Scan\VirusScannerInterface;
use App\Media\ShardUnavailable;
use App\Media\XmpRights;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Metadata, licence gate, download, scan, re-encode with rights, store.
 *
 * The order is the design. The licence gate runs before any bytes are pulled,
 * so a file we may not republish costs one metadata call and nothing else. The
 * scan runs before anything reaches a public bucket, because
 * media-storage-architecture.md §3.1 is fail-closed and somebody else's file is
 * exactly the case that rule exists for. The rights packet is written INTO the
 * re-encode rather than after it, because the re-encode is what drops the XMP
 * the file arrived with: photo-uploads.md §5f.
 *
 * @see docs/specs/coverage-provider.md §7
 *
 * @api
 */
#[AsMessageHandler]
final readonly class FetchCommonsPhotoHandler
{
    public function __construct(
        private CommonsApi $api,
        private CommonsPhotoRepository $photos,
        private PhotoProcessor $processor,
        private MediaStorage $storage,
        private VirusScannerInterface $scanner,
        private XmpRights $rights,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(FetchCommonsPhoto $message): void
    {
        $row = $this->photos->find($message->file);
        if (null === $row || CommonsPhotoState::Pending->value !== $row['state']) {
            return;   // settled by an earlier delivery; a redelivery must change nothing
        }

        try {
            $info = $this->api->fileInfo($message->file);
        } catch (CommonsUnavailable $e) {
            $this->photos->markFailed($message->file, 'metadata: '.$e->getMessage());
            $this->logger->warning('Commons metadata failed', ['file' => $message->file, 'why' => $e->getMessage()]);

            return;
        }

        if (null === $info) {
            $this->photos->markUnusable($message->file, 'no_free_licence');

            return;
        }

        try {
            $bytes = $this->api->fetchThumb($info['thumbUrl'], PhotoProcessor::MAX_BYTES);
        } catch (CommonsUnavailable $e) {
            // Keep the cause, not just the category. A bare 'download_failed'
            // made the first real failure undiagnosable: the row said nothing
            // about whether Wikimedia had refused us, timed out, or served
            // something too large, and by the time anyone looked the log had
            // rolled. The column is 64 chars and settle() truncates.
            $this->photos->markFailed($message->file, 'download: '.$e->getMessage());
            $this->logger->warning('Commons download failed', ['file' => $message->file, 'why' => $e->getMessage()]);

            return;
        }

        try {
            $verdict = $this->scanner->scan($bytes);
        } catch (ScannerUnavailable) {
            $this->photos->markFailed($message->file, 'scanner_unavailable');   // fail closed

            return;
        }
        if ($verdict->infected) {
            $this->photos->markUnusable($message->file, 'infected');
            $this->logger->error('Commons file failed the scan', ['file' => $message->file, 'signature' => $verdict->signature]);

            return;
        }

        try {
            // Their author, their licence, their file page. The re-encode below
            // drops whatever XMP arrived with the file, so without this the
            // copy in our bucket would be an orphan: no author and no licence
            // in the bytes at all, on a work whose licence asks for exactly
            // that to travel with it. NOT the rider packet, which asserts our
            // own licence and points at our own photo page.
            $photo = $this->processor->process($bytes, $this->rights->forCommonsFile(
                $message->file,
                $info['credit'],
                $info['creditUser'],
                $info['license'],
            ));
        } catch (PhotoRejected $e) {
            $this->photos->markUnusable($message->file, $e->getMessage());

            return;
        }

        // A re-stamp keeps the bucket it is already in as well as the prefix:
        // the published URL names both, and re-resolving the continent could
        // move the photo if the shard configuration has changed since.
        $bucket = $row['storage_bucket'];
        if (null === $bucket) {
            try {
                $bucket = $this->storage->bucketFor($message->continent);
            } catch (ShardUnavailable) {
                // A continent with no bucket refuses, it never borrows another's
                // (photo-uploads.md §2). Retryable: configuration may arrive.
                $this->photos->markFailed($message->file, 'storage_unavailable');

                return;
            }
        }

        // A pending row that already carries a prefix is a re-stamp
        // (CommonsPhotoRepository::markForRestamp): keep it, so every URL
        // already published for this photo stays valid and only the bytes
        // behind it change. A first fetch has none and gets a fresh one.
        $prefix = $row['storage_prefix'] ?? 'published/'.Uuid::v4()->toRfc4122().'/'.bin2hex(random_bytes(4));
        $this->storage->store($bucket, $prefix, $photo);
        $this->photos->markReady(
            $message->file, $bucket, $prefix,
            $info['credit'], $info['creditUser'], $info['license'],
            $photo->width, $photo->height,
            $info['cameraLat'], $info['cameraLng'],
        );
    }
}
