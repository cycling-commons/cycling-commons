<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media\Entity;

use App\Media\MediaStatus;
use App\Media\MediaTakedownSource;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One stored photo and the facts harvested before metadata strip.
 *
 * @see docs/specs/photo-uploads.md §3
 *
 * @api
 */
#[ORM\Entity]
#[ORM\Table(name: 'media_upload')]
#[ORM\Index(name: 'idx_media_gc', columns: ['status', 'created_at'])]
#[ORM\Index(name: 'idx_media_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_media_submission', columns: ['submission_id'])]
#[ORM\Index(name: 'idx_media_takedown_reporter', columns: ['takedown_reporter_hash'], options: ['where' => 'takedown_reporter_hash IS NOT NULL'])]
#[ORM\Index(name: 'idx_media_item', columns: ['item_id'])]
class MediaUpload
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(name: 'user_id', type: Types::BIGINT, nullable: true)]
    private ?int $userId;

    #[ORM\Column(name: 'consent_record_id', type: 'uuid')]
    private Uuid $consentRecordId;

    /** Continent resolved at intake. */
    #[ORM\Column(type: Types::STRING, length: 2)]
    private string $continent;

    /**
     * Full public-bucket name recorded at intake; never re-derived.
     *
     * @see docs/specs/media-storage-architecture.md §2.1, §4
     */
    #[ORM\Column(name: 'storage_bucket', type: Types::STRING, length: 63, options: ['default' => ''])]
    private string $storageBucket = '';

    /**
     * Immutable key token: published/<uuid>/<rev>/. Null = nothing published.
     *
     * @see docs/specs/media-storage-architecture.md §4
     */
    #[ORM\Column(type: Types::STRING, length: 12, nullable: true)]
    private ?string $revision = null;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: MediaStatus::class)]
    private MediaStatus $status = MediaStatus::Pending;

    #[ORM\Column(type: Types::INTEGER)]
    private int $width;

    #[ORM\Column(type: Types::INTEGER)]
    private int $height;

    /** Byte size of the stored original. */
    #[ORM\Column(type: Types::INTEGER)]
    private int $bytes;

    /** EXIF capture date; published at month granularity. @see docs/specs/photo-uploads.md §5 */
    #[ORM\Column(name: 'taken_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $takenAt;

    /** GPS, stripped after distance. @see docs/specs/photo-uploads.md §3 */
    #[ORM\Column(name: 'gps_lat', type: Types::FLOAT, nullable: true)]
    private ?float $gpsLat;

    #[ORM\Column(name: 'gps_lng', type: Types::FLOAT, nullable: true)]
    private ?float $gpsLng;

    /** Pin distance after GPS is stripped. @see docs/specs/photo-uploads.md §3 */
    #[ORM\Column(name: 'gps_distance_m', type: Types::INTEGER, nullable: true)]
    private ?int $gpsDistanceM = null;

    /**
     * The submission pin `gps_distance_m` was measured to; null when there is
     * no distance. A pin is public, not the rider's position. When the item's
     * pin moves later, PhotoValidator adds how far it moved to the distance.
     *
     * @see docs/specs/photo-uploads.md §5g
     */
    #[ORM\Column(name: 'gps_distance_pin_lat', type: Types::FLOAT, nullable: true)]
    private ?float $gpsDistancePinLat = null;

    #[ORM\Column(name: 'gps_distance_pin_lng', type: Types::FLOAT, nullable: true)]
    private ?float $gpsDistancePinLng = null;

    #[ORM\Column(name: 'submission_id', type: Types::BIGINT, nullable: true)]
    private ?int $submissionId = null;

    /** Item this photo belongs to after approval. @see docs/specs/photo-uploads.md §6 */
    #[ORM\Column(name: 'item_id', type: Types::BIGINT, nullable: true)]
    private ?int $itemId = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** When a curator decided; reject retention starts here. */
    #[ORM\Column(name: 'decided_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    /** Tombstone: objects gone, row kept for audit. */
    #[ORM\Column(name: 'objects_deleted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $objectsDeletedAt = null;

    /** Frozen credit after account deletion; '' = anonymous. @see docs/specs/photo-uploads.md §6 */
    #[ORM\Column(name: 'credit_frozen', type: Types::STRING, length: 120, nullable: true)]
    private ?string $creditFrozen = null;

    /** Uploader takedown withholds immediately. @see docs/specs/photo-uploads.md §6b */
    #[ORM\Column(name: 'takedown_requested_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $takedownRequestedAt = null;

    /** Rider's words: rights claim vs change of mind. */
    #[ORM\Column(name: 'takedown_reason', type: Types::TEXT, nullable: true)]
    private ?string $takedownReason = null;

    /** Uploader withholds; third_party queues. @see docs/specs/photo-uploads.md §6b, §6c */
    #[ORM\Column(name: 'takedown_source', type: Types::STRING, length: 16, nullable: true)]
    private ?string $takedownSource = null;

    /** Third-party category only. @see docs/specs/photo-uploads.md §6c */
    #[ORM\Column(name: 'takedown_category', type: Types::STRING, length: 32, nullable: true)]
    private ?string $takedownCategory = null;

    /** Reporter reply address; 90-day retention. @see docs/specs/photo-uploads.md §6c */
    #[ORM\Column(name: 'takedown_contact', type: Types::STRING, length: 320, nullable: true)]
    private ?string $takedownContact = null;

    /** Salted reporter-IP hash; never reversible. @see docs/specs/photo-uploads.md §6c */
    #[ORM\Column(name: 'takedown_reporter_hash', type: Types::STRING, length: 64, nullable: true)]
    private ?string $takedownReporterHash = null;

    /** Grant/decline time; contact retention starts here. */
    #[ORM\Column(name: 'takedown_resolved_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $takedownResolvedAt = null;

    /** Whether this request withheld the photo (not derived from category). @see docs/specs/photo-uploads.md §6c */
    #[ORM\Column(name: 'takedown_withheld', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $takedownWithheld = false;

    /**
     * Final decided third-party categories for this photo.
     *
     * @var list<string>
     *
     * @see docs/specs/photo-uploads.md §6c
     */
    #[ORM\Column(name: 'takedown_decided_categories', type: Types::JSON)]
    private array $takedownDecidedCategories = [];

    /** Legal hold: no public, no curator, no delete except admin. @see docs/specs/photo-uploads.md §6d */
    #[ORM\Column(name: 'escalated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $escalatedAt = null;

    /** Who escalated; admin can ask without re-showing the photo. */
    #[ORM\Column(name: 'escalated_by_id', type: Types::INTEGER, nullable: true)]
    private ?int $escalatedById = null;

    /**
     * What somebody who cannot see the photo needs to know.
     *
     * Optional, and the render side falls back to the item's name rather than
     * to `alt=""`. Not part of the licensed work: a description OF a photo is
     * not the photo, so a curator may fix it without it being a licence
     * question. @see docs/specs/photo-uploads.md §5e
     */
    #[ORM\Column(name: 'alt_text', type: Types::TEXT, nullable: true)]
    private ?string $altText = null;

    /** Curator's description before an admin looks. */
    #[ORM\Column(name: 'escalated_reason', type: Types::TEXT, nullable: true)]
    private ?string $escalatedReason = null;

    /**
     * The curator who confirmed this photo was taken at the pin.
     *
     * The file's GPS is stripped at intake (§3), so a photo that carried none
     * can never be measured afterwards; a named person vouching for the spot
     * is what makes it count as within range on a scenic view.
     *
     * @see docs/specs/photo-uploads.md §5g
     */
    #[ORM\Column(name: 'location_confirmed_by', type: Types::BIGINT, nullable: true)]
    private ?int $locationConfirmedBy = null;

    #[ORM\Column(name: 'location_confirmed_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $locationConfirmedAt = null;

    /**
     * The item pin the curator confirmed the photo was taken at. The
     * confirmation vouches for that pin only: once the pin has moved, a scenic
     * view no longer counts it (PhotoValidator).
     *
     * @see docs/specs/photo-uploads.md §5g
     */
    #[ORM\Column(name: 'location_confirmed_pin_lat', type: Types::FLOAT, nullable: true)]
    private ?float $locationConfirmedPinLat = null;

    #[ORM\Column(name: 'location_confirmed_pin_lng', type: Types::FLOAT, nullable: true)]
    private ?float $locationConfirmedPinLng = null;

    public function __construct(
        Uuid $id,
        int $userId,
        Uuid $consentRecordId,
        string $continent,
        int $width,
        int $height,
        int $bytes,
        ?\DateTimeImmutable $takenAt = null,
        ?float $gpsLat = null,
        ?float $gpsLng = null,
        // Empty bucket only for entity tests that never touch storage.
        string $bucket = '',
    ) {
        $this->id = $id;
        $this->userId = $userId;
        $this->consentRecordId = $consentRecordId;
        $this->continent = strtoupper($continent);
        $this->storageBucket = $bucket;
        $this->revision = self::mintRevision();
        $this->width = $width;
        $this->height = $height;
        $this->bytes = $bytes;
        $this->takenAt = $takenAt;
        $this->gpsLat = $gpsLat;
        $this->gpsLng = $gpsLng;
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * Intake constructor: identity, bytes, shard. Decode is the worker's.
     *
     * @see docs/specs/media-storage-architecture.md §3
     */
    public static function quarantined(
        Uuid $id,
        int $userId,
        Uuid $consentRecordId,
        string $continent,
        string $bucket,
        int $bytes,
    ): self {
        $upload = new self($id, $userId, $consentRecordId, $continent, 0, 0, $bytes);
        $upload->storageBucket = $bucket;
        $upload->revision = null;
        $upload->status = MediaStatus::PendingScan;

        return $upload;
    }

    /**
     * Point the row at already-written public objects (objects before row).
     *
     * @see docs/specs/media-storage-architecture.md §3
     */
    public function release(string $revision, int $width, int $height, int $bytes, ?\DateTimeImmutable $takenAt): void
    {
        $this->revision = $revision;
        $this->width = $width;
        $this->height = $height;
        $this->bytes = $bytes;
        $this->takenAt = $takenAt;
        $this->status = MediaStatus::Pending;
    }

    /** Stamp revision without changing status (immutable-key backfill). @see docs/specs/media-storage-architecture.md §4.1 */
    public function stampRevision(string $revision): void
    {
        $this->revision = $revision;
    }

    /** Infected/unreadable: tombstone with no revision. */
    public function rejectUnreleased(): void
    {
        $this->status = MediaStatus::Rejected;
        $this->decidedAt = new \DateTimeImmutable();
        $this->objectsDeletedAt = new \DateTimeImmutable();
    }

    /** Re-pin shard before anything is published. @see docs/specs/media-storage-architecture.md §2.1 */
    public function reshard(string $continent, string $bucket): void
    {
        if (null !== $this->revision) {
            throw new \LogicException(\sprintf('Upload %s has published objects; its bucket is now its address.', $this->id->toRfc4122()));
        }
        $this->continent = strtoupper($continent);
        $this->storageBucket = $bucket;
    }

    /**
     * Record where this upload's objects already live.
     *
     * Deliberately not reshard(): that refuses once objects are published,
     * because moving a published photo changes its public URL. This fills a
     * blank left by the migration that added the column, so it is allowed
     * after publication - but only ever on a blank, never as a move.
     *
     * @throws \LogicException when an address is already recorded
     *
     * @see docs/specs/media-storage-architecture.md §2.1
     */
    public function adoptStorageBucket(string $bucket): void
    {
        if ('' !== $this->storageBucket) {
            throw new \LogicException(\sprintf('Upload %s already records bucket "%s"; its bucket is its address and cannot be reassigned.', $this->id->toRfc4122(), $this->storageBucket));
        }
        $this->storageBucket = $bucket;
    }

    /** Opaque per-run token. @see docs/specs/media-storage-architecture.md §4 */
    public static function mintRevision(): string
    {
        return bin2hex(random_bytes(4));
    }

    /**
     * published/<uuid>/<rev> prefix; throws while quarantined.
     *
     * @see docs/specs/media-storage-architecture.md §4
     */
    public function getPathPrefix(): string
    {
        if (null === $this->revision) {
            throw new \LogicException(\sprintf('Upload %s has published nothing yet, so it has no object prefix.', $this->id->toRfc4122()));
        }

        return self::prefixFor($this->id->toRfc4122(), $this->revision);
    }

    /** Same prefix from raw columns (SQL readers). */
    public static function prefixFor(string $uuid, string $revision): string
    {
        return 'published/'.$uuid.'/'.$revision;
    }

    public function hasPublishedObjects(): bool
    {
        return null !== $this->revision;
    }

    public function getRevision(): ?string
    {
        return $this->revision;
    }

    public function getStorageBucket(): string
    {
        return $this->storageBucket;
    }

    /** Private-storage key of unscanned bytes. */
    public function getQuarantineKey(): string
    {
        return $this->id->toRfc4122();
    }

    public function claim(int $submissionId): void
    {
        $this->submissionId = $submissionId;
    }

    /**
     * Keep the pin distance and the pin it was measured to; destroy coordinates.
     *
     * @see docs/specs/photo-uploads.md §3, §5g
     */
    public function resolveGps(?int $distanceM, ?float $pinLat, ?float $pinLng): void
    {
        $measured = null !== $distanceM && null !== $pinLat && null !== $pinLng;
        $this->gpsDistanceM = $distanceM;
        $this->gpsDistancePinLat = $measured ? $pinLat : null;
        $this->gpsDistancePinLng = $measured ? $pinLng : null;
        $this->gpsLat = null;
        $this->gpsLng = null;
    }

    /** Store GPS on an unclaimed row only. @see docs/specs/photo-uploads.md §3 */
    public function rememberGps(?float $lat, ?float $lng): void
    {
        if (null !== $this->submissionId) {
            throw new \LogicException('Coordinates must never be written onto a claimed upload.');
        }
        $this->gpsLat = $lat;
        $this->gpsLng = $lng;
    }

    public function approve(?int $itemId): void
    {
        $this->status = MediaStatus::Approved;
        $this->itemId = $itemId;
        $this->decidedAt = new \DateTimeImmutable();
    }

    /**
     * A curator vouches that this photo was taken at the pin, as it stands at
     * `$pinLat`, `$pinLng`.
     *
     * Only an approved photo attached to an item has a pin to be taken at.
     *
     * @throws \LogicException when the upload is not approved or has no item
     *
     * @see docs/specs/photo-uploads.md §5g
     */
    public function confirmLocation(int $curatorId, \DateTimeImmutable $at, float $pinLat, float $pinLng): void
    {
        if (MediaStatus::Approved !== $this->status || null === $this->itemId) {
            throw new \LogicException(\sprintf('Upload %s is not an approved photo on an item, so there is no pin to confirm it was taken at.', $this->id->toRfc4122()));
        }
        $this->locationConfirmedBy = $curatorId;
        $this->locationConfirmedAt = $at;
        $this->locationConfirmedPinLat = $pinLat;
        $this->locationConfirmedPinLng = $pinLng;
    }

    public function isLocationConfirmed(): bool
    {
        return null !== $this->locationConfirmedAt;
    }

    public function getLocationConfirmedBy(): ?int
    {
        return $this->locationConfirmedBy;
    }

    public function getLocationConfirmedAt(): ?\DateTimeImmutable
    {
        return $this->locationConfirmedAt;
    }

    /**
     * `[lat, lng]` of the pin the confirmation was made at, or null.
     *
     * @return array{0: float, 1: float}|null
     */
    public function getLocationConfirmedPin(): ?array
    {
        return null === $this->locationConfirmedPinLat || null === $this->locationConfirmedPinLng
            ? null
            : [$this->locationConfirmedPinLat, $this->locationConfirmedPinLng];
    }

    public function reject(): void
    {
        $this->status = MediaStatus::Rejected;
        $this->decidedAt = new \DateTimeImmutable();
    }

    public function markObjectsDeleted(): void
    {
        $this->objectsDeletedAt = new \DateTimeImmutable();
    }

    /** Drop account link; freeze credit. @see docs/specs/photo-uploads.md §6 */
    public function anonymize(string $frozenCredit = ''): void
    {
        $this->userId = null;
        $this->creditFrozen = $frozenCredit;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function getConsentRecordId(): Uuid
    {
        return $this->consentRecordId;
    }

    public function getContinent(): string
    {
        return $this->continent;
    }

    public function getStatus(): MediaStatus
    {
        return $this->status;
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    public function getBytes(): int
    {
        return $this->bytes;
    }

    public function getTakenAt(): ?\DateTimeImmutable
    {
        return $this->takenAt;
    }

    public function getGpsLat(): ?float
    {
        return $this->gpsLat;
    }

    public function getGpsLng(): ?float
    {
        return $this->gpsLng;
    }

    public function getGpsDistanceM(): ?int
    {
        return $this->gpsDistanceM;
    }

    /**
     * `[lat, lng]` of the pin `gps_distance_m` was measured to, or null.
     *
     * @return array{0: float, 1: float}|null
     */
    public function getGpsDistancePin(): ?array
    {
        return null === $this->gpsDistancePinLat || null === $this->gpsDistancePinLng
            ? null
            : [$this->gpsDistancePinLat, $this->gpsDistancePinLng];
    }

    public function getSubmissionId(): ?int
    {
        return $this->submissionId;
    }

    /** Trimmed, or null: an empty string must never reach the `alt` attribute. */
    public function setAltText(?string $altText): void
    {
        $altText = null === $altText ? null : trim($altText);
        $this->altText = ('' === $altText) ? null : $altText;
    }

    public function getAltText(): ?string
    {
        return $this->altText;
    }

    public function getItemId(): ?int
    {
        return $this->itemId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function getObjectsDeletedAt(): ?\DateTimeImmutable
    {
        return $this->objectsDeletedAt;
    }

    public function getCreditFrozen(): ?string
    {
        return $this->creditFrozen;
    }

    public function requestTakedown(string $reason): void
    {
        $this->takedownRequestedAt = new \DateTimeImmutable();
        $this->takedownReason = $reason;
        $this->takedownSource = MediaTakedownSource::Uploader;
        $this->takedownResolvedAt = null;
        // Uploader owns the row: always withholds. @see docs/specs/photo-uploads.md §6b
        $this->takedownWithheld = true;
    }

    /** Third-party report; withhold is the service's call. @see docs/specs/photo-uploads.md §6c */
    public function reportThirdParty(string $category, string $reason, ?string $contact, string $reporterHash, bool $withheld): void
    {
        $this->takedownRequestedAt = new \DateTimeImmutable();
        $this->takedownReason = $reason;
        $this->takedownSource = MediaTakedownSource::ThirdParty;
        $this->takedownCategory = $category;
        $this->takedownContact = $contact;
        $this->takedownReporterHash = $reporterHash;
        $this->takedownResolvedAt = null;
        $this->takedownWithheld = $withheld;
    }

    /** Decline: republish; keep reason; third-party category is final. @see docs/specs/photo-uploads.md §6b, §6c */
    public function declineTakedown(): void
    {
        if (null !== $this->takedownCategory && !\in_array($this->takedownCategory, $this->takedownDecidedCategories, true)) {
            $this->takedownDecidedCategories[] = $this->takedownCategory;
        }
        $this->takedownRequestedAt = null;
        $this->takedownResolvedAt = new \DateTimeImmutable();
        $this->takedownWithheld = false;
    }

    /** Abusive-report undo: republish without spending the category slot. @see docs/specs/photo-uploads.md §6c */
    public function dismissTakedownAsAbuse(): void
    {
        $this->takedownRequestedAt = null;
        $this->takedownResolvedAt = new \DateTimeImmutable();
        $this->takedownWithheld = false;
    }

    /** Grant-side bookkeeping; disposal is MediaDisposalService. */
    public function resolveTakedown(): void
    {
        $this->takedownResolvedAt = new \DateTimeImmutable();
        $this->takedownWithheld = false;
    }

    public function hasDecidedTakedown(string $category): bool
    {
        return \in_array($category, $this->takedownDecidedCategories, true);
    }

    /** 90-day contact sweep. @see docs/specs/photo-uploads.md §6c */
    public function clearTakedownContact(): void
    {
        $this->takedownContact = null;
    }

    public function getTakedownSource(): ?string
    {
        return $this->takedownSource;
    }

    public function getTakedownCategory(): ?string
    {
        return $this->takedownCategory;
    }

    public function getTakedownContact(): ?string
    {
        return $this->takedownContact;
    }

    public function getTakedownReporterHash(): ?string
    {
        return $this->takedownReporterHash;
    }

    public function getTakedownResolvedAt(): ?\DateTimeImmutable
    {
        return $this->takedownResolvedAt;
    }

    public function getTakedownRequestedAt(): ?\DateTimeImmutable
    {
        return $this->takedownRequestedAt;
    }

    public function getTakedownReason(): ?string
    {
        return $this->takedownReason;
    }

    public function escalate(int $curatorId, string $reason): void
    {
        $this->escalatedAt = new \DateTimeImmutable();
        $this->escalatedById = $curatorId;
        $this->escalatedReason = $reason;
    }

    /** Admin: not illegal; resume normal moderation. */
    public function releaseEscalation(): void
    {
        $this->escalatedAt = null;
    }

    /** Legal hold. @see docs/specs/photo-uploads.md §6d */
    public function isEscalated(): bool
    {
        return null !== $this->escalatedAt;
    }

    public function getEscalatedAt(): ?\DateTimeImmutable
    {
        return $this->escalatedAt;
    }

    public function getEscalatedById(): ?int
    {
        return $this->escalatedById;
    }

    public function getEscalatedReason(): ?string
    {
        return $this->escalatedReason;
    }

    /** Undecided takedown of either source. */
    public function isTakedownPending(): bool
    {
        return null !== $this->takedownRequestedAt && null === $this->objectsDeletedAt;
    }

    /** Withheld while pending; stored at request time, never recomputed. @see docs/specs/photo-uploads.md §6c */
    public function isTakedownWithheld(): bool
    {
        return $this->isTakedownPending() && $this->takedownWithheld;
    }
}
