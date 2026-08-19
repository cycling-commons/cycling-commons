<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media\MessageHandler;

use App\Catalog\Entity\Submission;
use App\Media\ContinentResolver;
use App\Media\Entity\MediaUpload;
use App\Media\GpsDistance;
use App\Media\MediaAction;
use App\Media\MediaEventLog;
use App\Media\MediaStorage;
use App\Media\Message\ScanAndReleaseUpload;
use App\Media\PhotoProcessor;
use App\Media\PhotoRejected;
use App\Media\ProcessedPhoto;
use App\Media\ShardUnavailable;
use App\Media\Scan\VirusScannerInterface;
use App\Media\XmpRights;
use App\Messaging\MessageService;
use App\Messaging\UserMessageKind;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * The release gate (docs/specs/media-storage-architecture.md §3): quarantine ->
 * scan -> decode -> physical release.
 *
 * **The gate is physical, not a flag.** A photo is published because its
 * derivatives exist in the public bucket, and they exist because this handler
 * put them there after a clean verdict. The row's revision is written AFTER
 * the objects, so there is no instant at which a status column is the only
 * thing between an unscanned file and a reader.
 *
 * Three verdicts, three endings:
 *
 * - **infected** - the object is deleted, the row is a tombstone, the rider is
 *   told. Terminal in one pass; an infection is an answer, not a failure.
 * - **scanner error** - nothing is touched and the exception escapes, so
 *   Messenger retries and the bytes stay quarantined. Fail-closed (§3.1): the
 *   one thing that must never happen is silence being read as clean.
 * - **clean** - decode, write the derivatives, point the row at them, delete
 *   the quarantine object.
 *
 * Redelivery is harmless because the guard is a pair: the row must still be
 * quarantined AND the quarantine object must still be there. A message
 * redelivered after a successful release finds a released row; one redelivered
 * after a crash between the write and the flush finds the object gone and the
 * row still quarantined, and re-runs the release onto a FRESH revision rather
 * than overwriting the objects the previous attempt wrote - which is the whole
 * reason keys are immutable (§4).
 *
 * @api Handles ScanAndReleaseUpload, dispatched by MediaController::upload().
 */
#[AsMessageHandler]
final readonly class ScanAndReleaseUploadHandler
{
    /**
     * The wizard's patience window (docs/specs/photo-uploads.md §4). A release
     * inside it needs no message - the rider watched it land. Past it they were
     * told "we will let you know", and this is the promise being kept.
     *
     * A client-side limit mirrored here for ONE purpose, and it must never
     * become anything else: nothing about these 30 seconds may cause a scan to
     * be abandoned.
     */
    private const int PATIENCE_S = 30;

    public function __construct(
        private EntityManagerInterface $em,
        private MediaStorage $storage,
        private VirusScannerInterface $scanner,
        private PhotoProcessor $processor,
        private XmpRights $rights,
        private ContinentResolver $continents,
        private MediaEventLog $events,
        private MessageService $messages,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ScanAndReleaseUpload $message): void
    {
        if (!Uuid::isValid($message->mediaId)) {
            $this->logger->error('ScanAndReleaseUpload carried something that is not an upload id: {id}', ['id' => $message->mediaId]);

            return;
        }

        $upload = $this->em->find(MediaUpload::class, Uuid::fromString($message->mediaId));
        if (null === $upload) {
            // Purged while the message waited (Trash, account deletion, orphan
            // collection). Nothing to release and nothing wrong.
            return;
        }
        if (!$upload->getStatus()->isQuarantined()) {
            return;
        }

        $key = $upload->getQuarantineKey();
        $bytes = $this->storage->readQuarantine($key);
        if (null === $bytes) {
            /* No object and a still-quarantined row. Either the endpoint died
               between the write and the flush, or an operator removed it. Not
               retryable - a retry would read the same nothing - so the row is
               settled here rather than left to sit pending forever. */
            $this->refuse($upload, MediaAction::ScanUnreadable, 'quarantine object missing');

            return;
        }

        // Throws ScannerUnavailable when CLAMAV_REQUIRED is on and the scanner
        // is not there. Deliberately NOT caught: Messenger retries, the bytes
        // stay quarantined, and nothing is published on a non-answer.
        $verdict = $this->scanner->scan($bytes);

        if ($verdict->infected) {
            $this->storage->deleteQuarantine($key);
            $this->refuse($upload, MediaAction::ScanInfected, $verdict->signature ?? 'unnamed signature');

            return;
        }

        try {
            $processed = $this->processor->process($bytes, $this->rights->forPhoto($upload->getId()));
        } catch (PhotoRejected $rejected) {
            /* The decode this tier exists to host said no - a bomb, an
               unreadable container, a format we do not take. The endpoint used
               to answer this to the rider's face; it is a message now, and the
               hostile image exhausted a worker that gets restarted rather than
               a web host that is serving pages (§3.2). */
            $this->storage->deleteQuarantine($key);
            $this->refuse($upload, MediaAction::ScanUnreadable, $rejected->reason);

            return;
        }

        $this->release($upload, $processed, $message, $verdict->skipped);
    }

    /**
     * Objects first, row second, quarantine last - the order IS the gate.
     */
    private function release(MediaUpload $upload, ProcessedPhoto $processed, ScanAndReleaseUpload $message, bool $unscanned): void
    {
        /* The shard question the endpoint could only half-answer. With no pin
           the web tier had nothing but the default; the photo's own
           coordinates exist only after this decode. Legal precisely because
           nothing is published yet. */
        if (null === $message->pinLat || null === $message->pinLng) {
            // Pre-2026-08-18 messages only: the pin is required at intake now.
            $continent = $this->continents->resolve($processed->gpsLat, $processed->gpsLng);
            if (null !== $continent) {
                try {
                    [$shard, $bucket] = $this->storage->activeFor($continent);
                    $upload->reshard($continent, $shard, $bucket);
                } catch (ShardUnavailable) {
                    // The true continent still goes on the row; the bytes keep
                    // the address intake recorded: that bucket verifiably exists.
                    $upload->reshard($continent, $upload->getStorageShard(), $upload->getStorageBucket());
                }
            }
        }

        $revision = MediaUpload::mintRevision();
        $prefix = MediaUpload::prefixFor($upload->getId()->toRfc4122(), $revision);
        $this->storage->store($upload->getStorageBucket(), $prefix, $processed);

        $upload->release(
            $revision,
            $processed->width,
            $processed->height,
            \strlen($processed->orig),
            $processed->takenAt,
        );
        $this->resolveCoordinates($upload, $processed, $message);
        $this->storage->deleteQuarantine($upload->getQuarantineKey());

        $this->events->append(
            $upload->getId(),
            null,
            MediaAction::Released,
            $unscanned ? 'released WITHOUT a real verdict (no scanner)' : 'clean',
        );
        $this->tellRiderIfTheyStoppedWaiting($upload);
        $this->em->flush();
    }

    /**
     * The photo's coordinates, used exactly once and then destroyed
     * (docs/specs/photo-uploads.md §3) - with one new case the synchronous
     * flow never had.
     *
     * A row claimed while it was still quarantined has ALREADY been through
     * MediaClaimService, which ran the destruction against columns that were
     * empty because the decode had not happened yet. Writing the coordinates
     * onto it now would resurrect exactly the data intake promised to destroy,
     * so the distance is computed here, from the submission's own pin, and the
     * coordinates never reach the database at all.
     */
    private function resolveCoordinates(MediaUpload $upload, ProcessedPhoto $processed, ScanAndReleaseUpload $message): void
    {
        $submissionId = $upload->getSubmissionId();
        if (null === $submissionId) {
            $upload->rememberGps($processed->gpsLat, $processed->gpsLng);

            return;
        }

        [$pinLat, $pinLng] = $this->submissionPin($submissionId) ?? [$message->pinLat, $message->pinLng];
        $upload->resolveGps(GpsDistance::metres($processed->gpsLat, $processed->gpsLng, $pinLat, $pinLng));
    }

    /**
     * The submission's own pin, which outranks the one the wizard sent: the
     * rider may have moved it between choosing the photo and pressing Send.
     *
     * @return array{?float, ?float}|null
     */
    private function submissionPin(int $submissionId): ?array
    {
        $submission = $this->em->find(Submission::class, $submissionId);
        if (null === $submission) {
            return null;
        }
        $geom = json_decode((string) $submission->getGeom(), true);
        if (!\is_array($geom) || !isset($geom['coordinates'][0], $geom['coordinates'][1])) {
            return null;
        }

        return [(float) $geom['coordinates'][1], (float) $geom['coordinates'][0]];
    }

    /** Infected, unreadable, or missing: settled, tombstoned, and said out loud. */
    private function refuse(MediaUpload $upload, string $action, string $note): void
    {
        $upload->rejectUnreleased();
        $this->events->append($upload->getId(), null, $action, $note);

        $userId = $upload->getUserId();
        if (null !== $userId) {
            $this->messages->sendSystem(
                $userId,
                UserMessageKind::MediaScanRejected,
                'media',
                $upload->getItemId() ?? 0,
                '',
                'messages.body.'.UserMessageKind::MediaScanRejected->value,
            );
        }

        $this->logger->warning('Refused a quarantined upload ({action}): {note}', [
            'action' => $action,
            'note' => $note,
            'media' => $upload->getId()->toRfc4122(),
        ]);
        $this->em->flush();
    }

    private function tellRiderIfTheyStoppedWaiting(MediaUpload $upload): void
    {
        $userId = $upload->getUserId();
        if (null === $userId) {
            return;
        }
        $waited = (new \DateTimeImmutable())->getTimestamp() - $upload->getCreatedAt()->getTimestamp();
        if ($waited < self::PATIENCE_S) {
            return;
        }

        $this->messages->sendSystem(
            $userId,
            UserMessageKind::MediaReady,
            'media',
            $upload->getItemId() ?? 0,
            '',
            'messages.body.'.UserMessageKind::MediaReady->value,
        );
    }
}
