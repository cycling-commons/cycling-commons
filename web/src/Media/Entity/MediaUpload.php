<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media\Entity;

use App\Media\MediaStatus;
use App\Media\MediaTakedownCategory;
use App\Media\MediaTakedownSource;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One stored photo: three WebP objects under photos/<uuid>/ in the continent's
 * bucket, plus the facts harvested from the file before its metadata was
 * stripped (docs/specs/photo-uploads.md §3).
 *
 * Path secrecy is honest secrecy, not access control: a UUIDv4 carries ~122
 * random bits so blind enumeration is impractical, but the path is still only
 * a secret in a URL, and the variant names are fixed, so anyone holding one
 * variant's URL can derive its siblings. Both are accepted for v1 —
 * docs/specs/photo-uploads.md §2 states the reasoning and the alternative that
 * was not chosen.
 *
 * user_id is nullable because account deletion anonymizes the credit on
 * approved photos rather than deleting contributions to the commons
 * (docs/specs/photo-uploads.md §6). It carries no foreign key, matching
 * submission.user_id.
 *
 * @api Media domain entity.
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

    /** Storage shard: the two-letter continent code this object lives in. */
    #[ORM\Column(type: Types::STRING, length: 2)]
    private string $continent;

    #[ORM\Column(type: Types::STRING, length: 10, enumType: MediaStatus::class)]
    private MediaStatus $status = MediaStatus::Pending;

    #[ORM\Column(type: Types::INTEGER)]
    private int $width;

    #[ORM\Column(type: Types::INTEGER)]
    private int $height;

    /** Byte size of the stored original. */
    #[ORM\Column(type: Types::INTEGER)]
    private int $bytes;

    /** EXIF capture date. Published only at MONTH granularity (§5). */
    #[ORM\Column(name: 'taken_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $takenAt;

    /** PRIVATE and short-lived: nulled at intake by resolveGps(). Never served. */
    #[ORM\Column(name: 'gps_lat', type: Types::FLOAT, nullable: true)]
    private ?float $gpsLat;

    #[ORM\Column(name: 'gps_lng', type: Types::FLOAT, nullable: true)]
    private ?float $gpsLng;

    /** All that survives of the coordinates: how far the shot was from the pin. */
    #[ORM\Column(name: 'gps_distance_m', type: Types::INTEGER, nullable: true)]
    private ?int $gpsDistanceM = null;

    #[ORM\Column(name: 'submission_id', type: Types::BIGINT, nullable: true)]
    private ?int $submissionId = null;

    /** Set at approval: the item this photo now belongs to (§6 credit anonymization). */
    #[ORM\Column(name: 'item_id', type: Types::BIGINT, nullable: true)]
    private ?int $itemId = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** When a curator decided. The retention window for rejects measures from here. */
    #[ORM\Column(name: 'decided_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    /** Tombstone marker: the bucket objects are gone, the row is kept for audit. */
    #[ORM\Column(name: 'objects_deleted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $objectsDeletedAt = null;

    /**
     * Set only when the account is deleted (docs/specs/photo-uploads.md §6): the
     * credit to render from then on, '' meaning anonymous. Null while the
     * account still exists, because until then the credit is resolved live from
     * the profile.
     */
    #[ORM\Column(name: 'credit_frozen', type: Types::STRING, length: 120, nullable: true)]
    private ?string $creditFrozen = null;

    /**
     * The uploader has asked for this photo to come down
     * (docs/specs/photo-uploads.md §6b). Non-null withholds it from publication
     * from that instant, before any curator looks: if the claim is "that photo
     * is of me", leaving it up while somebody gets round to it is the wrong
     * default, and GDPR Art. 18 is explicit that restriction is available while
     * a request is being verified.
     */
    #[ORM\Column(name: 'takedown_requested_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $takedownRequestedAt = null;

    /** The rider's own words, which is what tells a rights claim from a change of mind. */
    #[ORM\Column(name: 'takedown_reason', type: Types::TEXT, nullable: true)]
    private ?string $takedownReason = null;

    /** MediaTakedownSource: uploader (withholds on the spot) or third_party (queues). Null = never asked. */
    #[ORM\Column(name: 'takedown_source', type: Types::STRING, length: 16, nullable: true)]
    private ?string $takedownSource = null;

    /** MediaTakedownCategory — third-party requests only; the uploader route has no category. */
    #[ORM\Column(name: 'takedown_category', type: Types::STRING, length: 32, nullable: true)]
    private ?string $takedownCategory = null;

    /**
     * The reporter's optional reply address — the only identity they are ever
     * asked for, and only if they want an answer. Deleted 90 days after the
     * decision (Art. 5(1)(e)); MediaGcCommand sweeps it.
     */
    #[ORM\Column(name: 'takedown_contact', type: Types::STRING, length: 320, nullable: true)]
    private ?string $takedownContact = null;

    /**
     * Salted hash of the reporter's IP: answers "is one person reporting forty
     * photos" without keeping a log of who read what. Never reversible, never
     * shown.
     */
    #[ORM\Column(name: 'takedown_reporter_hash', type: Types::STRING, length: 64, nullable: true)]
    private ?string $takedownReporterHash = null;

    /** When a curator decided (grant or decline). The 90-day contact retention measures from here. */
    #[ORM\Column(name: 'takedown_resolved_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $takedownResolvedAt = null;

    /**
     * Categories a curator has already decided for this photo. One decided
     * report per photo per category is FINAL: a later identical report matches
     * here and does not re-open the case, so a photo cannot be kept withheld —
     * or a curator kept busy — by a stream of fresh copies of the same claim.
     *
     * @var list<string>
     */
    #[ORM\Column(name: 'takedown_decided_categories', type: Types::JSON)]
    private array $takedownDecidedCategories = [];

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
    ) {
        $this->id = $id;
        $this->userId = $userId;
        $this->consentRecordId = $consentRecordId;
        $this->continent = strtoupper($continent);
        $this->width = $width;
        $this->height = $height;
        $this->bytes = $bytes;
        $this->takenAt = $takenAt;
        $this->gpsLat = $gpsLat;
        $this->gpsLng = $gpsLng;
        $this->createdAt = new \DateTimeImmutable();
    }

    /** Object key prefix inside the continent's bucket. */
    public function getPathPrefix(): string
    {
        return 'photos/'.$this->id->toRfc4122();
    }

    public function claim(int $submissionId): void
    {
        $this->submissionId = $submissionId;
    }

    /**
     * Intake's one and only use of the harvested coordinates: keep the distance
     * to the submission pin, destroy the coordinates
     * (docs/specs/photo-uploads.md §3). Called for every claimed row, with null
     * when the photo carried no GPS — so the columns end up empty either way.
     */
    public function resolveGps(?int $distanceM): void
    {
        $this->gpsDistanceM = $distanceM;
        $this->gpsLat = null;
        $this->gpsLng = null;
    }

    public function approve(?int $itemId): void
    {
        $this->status = MediaStatus::Approved;
        $this->itemId = $itemId;
        $this->decidedAt = new \DateTimeImmutable();
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

    /**
     * Account deletion: the photo stays in the commons, the account link goes.
     * What the credit becomes is the departing rider's own choice
     * (docs/specs/photo-uploads.md §6) — an empty frozen credit renders as
     * anonymous everywhere, a name keeps naming them after the account is gone.
     */
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

    public function getSubmissionId(): ?int
    {
        return $this->submissionId;
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
    }

    /**
     * A third party — somebody who may be IN the photo, with or without an
     * account — reports it (docs/specs/photo-uploads.md §6c). Unlike the
     * uploader route this records and changes nothing else: whether the photo
     * is withheld is MediaTakedownService's call, not the row's.
     */
    public function reportThirdParty(string $category, string $reason, ?string $contact, string $reporterHash): void
    {
        $this->takedownRequestedAt = new \DateTimeImmutable();
        $this->takedownReason = $reason;
        $this->takedownSource = MediaTakedownSource::ThirdParty;
        $this->takedownCategory = $category;
        $this->takedownContact = $contact;
        $this->takedownReporterHash = $reporterHash;
        $this->takedownResolvedAt = null;
    }

    /**
     * A curator has decided the request is not a rights claim
     * (docs/specs/photo-uploads.md §6b). The marker goes so the photo is
     * published again; the reason STAYS, because the next curator to look at
     * this upload should be able to see it was asked about before. A declined
     * third-party category joins the decided list and is final (§6c).
     */
    public function declineTakedown(): void
    {
        if (null !== $this->takedownCategory && !\in_array($this->takedownCategory, $this->takedownDecidedCategories, true)) {
            $this->takedownDecidedCategories[] = $this->takedownCategory;
        }
        $this->takedownRequestedAt = null;
        $this->takedownResolvedAt = new \DateTimeImmutable();
    }

    /** Grant-side bookkeeping: the disposal itself is MediaDisposalService's job. */
    public function resolveTakedown(): void
    {
        $this->takedownResolvedAt = new \DateTimeImmutable();
    }

    public function hasDecidedTakedown(string $category): bool
    {
        return \in_array($category, $this->takedownDecidedCategories, true);
    }

    /** The 90-day retention sweep (docs/specs/photo-uploads.md §6c); MediaGcCommand calls it. */
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

    /** A curator has an undecided request — of either source — for this photo. */
    public function isTakedownPending(): bool
    {
        return null !== $this->takedownRequestedAt && null === $this->objectsDeletedAt;
    }

    /**
     * Withheld from publication while the request waits. The uploader's own
     * request always withholds (they own the row; the worst case is somebody
     * hiding their own contribution). A third party's withholds ONLY in the
     * intimate-imagery/child category — anything else queuing invisible would
     * be a heckler's veto (docs/specs/photo-uploads.md §6c).
     */
    public function isTakedownWithheld(): bool
    {
        if (!$this->isTakedownPending()) {
            return false;
        }

        return MediaTakedownSource::ThirdParty !== $this->takedownSource
            || (null !== $this->takedownCategory && MediaTakedownCategory::autoWithholds($this->takedownCategory));
    }
}
