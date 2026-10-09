<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media\MessageHandler;

use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\Entity\RouteSuggestion;
use App\Catalog\Entity\Submission;
use App\Media\ContinentResolver;
use App\Media\Entity\MediaUpload;
use App\Media\GpsDistance;
use App\Media\MediaAction;
use App\Media\MediaClaimService;
use App\Media\MediaEventLog;
use App\Media\MediaStorage;
use App\Media\Message\ScanAndReleaseUpload;
use App\Media\PhotoProcessor;
use App\Media\PhotoRejected;
use App\Media\ProcessedPhoto;
use App\Media\Scan\VirusScannerInterface;
use App\Media\ShardUnavailable;
use App\Media\XmpRights;
use App\Messaging\MessageService;
use App\Messaging\UserMessageKind;
use App\Moderation\StatementDecision;
use App\Moderation\StatementGround;
use App\Moderation\StatementOfReasons;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Release gate: quarantine → scan → decode → public objects.
 *
 * @see docs/specs/media-storage-architecture.md §3
 *
 * @api
 */
#[AsMessageHandler]
final readonly class ScanAndReleaseUploadHandler
{
    /** Wizard 30s window; must not abort a scan. @see docs/specs/photo-uploads.md §4 */
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
            // Already purged (Trash, account deletion, orphan GC).
            return;
        }
        if (!$upload->getStatus()->isQuarantined()) {
            return;
        }

        $key = $upload->getQuarantineKey();
        $bytes = $this->storage->readQuarantine($key);
        if (null === $bytes) {
            // Missing object, still quarantined: settle, do not retry.
            $this->refuse($upload, MediaAction::ScanUnreadable, 'quarantine object missing');

            return;
        }

        // Fail-closed: no scanner throws, in every environment, so Messenger retries and the bytes stay in quarantine. @see docs/specs/media-storage-architecture.md §3.1
        $verdict = $this->scanner->scan($bytes);

        if ($verdict->infected) {
            $this->storage->deleteQuarantine($key);
            $this->refuse($upload, MediaAction::ScanInfected, $verdict->signature ?? 'unnamed signature');

            return;
        }

        try {
            $processed = $this->processor->process($bytes, $this->rights->forPhoto($upload->getId()));
        } catch (PhotoRejected $rejected) {
            // Decode refused (bomb/unreadable). Worker-only. @see docs/specs/media-storage-architecture.md §3.2
            $this->storage->deleteQuarantine($key);
            $this->refuse($upload, MediaAction::ScanUnreadable, $rejected->reason);

            return;
        }

        $this->release($upload, $processed, $message);
    }

    /** Objects first, then row, then quarantine delete. @see docs/specs/media-storage-architecture.md §3 */
    private function release(MediaUpload $upload, ProcessedPhoto $processed, ScanAndReleaseUpload $message): void
    {
        if (null === $message->pinLat || null === $message->pinLng) {
            // Legacy messages: pin is required at intake now.
            $continent = $this->continents->resolve($processed->gpsLat, $processed->gpsLng);
            if (null !== $continent) {
                try {
                    $upload->reshard($continent, $this->storage->bucketFor($continent));
                } catch (ShardUnavailable) {
                    // Shard pin: keep the intake bucket; record the true continent.
                    $upload->reshard($continent, $upload->getStorageBucket());
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

        $this->events->append(
            $upload->getId(),
            null,
            MediaAction::Released,
            'clean',
        );
        $this->tellRiderIfTheyStoppedWaiting($upload);
        $this->em->flush();

        // After the flush: a failed flush leaves the bytes for the redelivery to release.
        $this->storage->deleteQuarantine($upload->getQuarantineKey());
    }

    /** GPS → distance, then destroy. @see docs/specs/photo-uploads.md §3 */
    private function resolveCoordinates(MediaUpload $upload, ProcessedPhoto $processed, ScanAndReleaseUpload $message): void
    {
        $routeId = $upload->getRouteId();
        if (null !== $routeId) {
            // A route has no single pin: the nearest point of its line (photo-uploads.md §5i).
            $route = $this->em->find(RecommendedRoute::class, $routeId);
            $pin = null === $route || null === $processed->gpsLat || null === $processed->gpsLng
                ? null
                : GpsDistance::nearestOnLine($processed->gpsLat, $processed->gpsLng, MediaClaimService::lineOf($route));
            $upload->resolveGps(
                null === $pin ? null : GpsDistance::between($processed->gpsLat, $processed->gpsLng, $pin[0], $pin[1]),
                $pin[0] ?? null,
                $pin[1] ?? null,
            );

            return;
        }

        $submissionId = $upload->getSubmissionId();
        if (null === $submissionId) {
            $upload->rememberGps($processed->gpsLat, $processed->gpsLng);

            return;
        }

        [$pinLat, $pinLng] = $this->submissionPin($submissionId) ?? [$message->pinLat, $message->pinLng];
        $upload->resolveGps(GpsDistance::between($processed->gpsLat, $processed->gpsLng, $pinLat, $pinLng), $pinLat, $pinLng);
    }

    /**
     * Submission pin outranks the wizard pin.
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

    private function refuse(MediaUpload $upload, string $action, string $note): void
    {
        $upload->rejectUnreleased();
        $this->events->append($upload->getId(), null, $action, $note);

        $userId = $upload->getUserId();
        if (null !== $userId && !$this->ownerIsTrashed($upload)) {
            // Refused by the checks alone: the statement of reasons says so
            // (DSA Article 17(3)(c), content-reports.md §7).
            $this->messages->sendSystem(
                $userId,
                UserMessageKind::MediaScanRejected,
                'media',
                $upload->getItemId() ?? 0,
                '',
                'messages.body.'.UserMessageKind::MediaScanRejected->value,
                [],
                null,
                new StatementOfReasons(
                    StatementDecision::NotPublished,
                    StatementGround::FileRefused,
                    '',
                    $upload->getId()->toRfc4122(),
                    automated: true,
                    factsKey: 'dsa_statement.facts.file_refused',
                    aboutPhoto: true,
                ),
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
        if (null === $userId || $this->ownerIsTrashed($upload)) {
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

    /**
     * The photo belongs to a contribution a curator moved to Trash: the
     * rider hears nothing about it (moderation-and-contribution.md §6).
     */
    private function ownerIsTrashed(MediaUpload $upload): bool
    {
        $submissionId = $upload->getSubmissionId();
        if (null !== $submissionId) {
            return true === $this->em->find(Submission::class, $submissionId)?->isTrashed();
        }
        $suggestionId = $upload->getRouteSuggestionId();
        if (null !== $suggestionId) {
            return true === $this->em->find(RouteSuggestion::class, $suggestionId)?->isTrashed();
        }
        $routeId = $upload->getRouteId();

        return null !== $routeId && true === $this->em->find(RecommendedRoute::class, $routeId)?->isTrashed();
    }
}
