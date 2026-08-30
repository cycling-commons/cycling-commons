<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Support\Entity;

use App\Support\ReportGround;
use App\Support\ReportStatus;
use App\Support\ReportTarget;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One person's notice that something on the map should not be there.
 *
 * Article 16 of the DSA: any person, no account, any content. So the reporter
 * is **not** a user relation. They may be anonymous, they may never come back,
 * and the only thing we keep about them is an address they chose to give and a
 * salted hash of their IP for rate limiting.
 *
 * **The target is polymorphic on purpose.** A route, a catalogue item, a region
 * description, a display name and a message live in five different tables, and
 * a foreign key to each would mean five nullable columns and five joins on a
 * desk that only ever shows a label. What a curator needs is: what kind of
 * thing, which one, and what did it say at the time. The third is why
 * `targetLabel` is a **snapshot**: by the time a curator looks, the text may
 * have been edited, and a report about text that no longer exists still has to
 * be readable.
 *
 * @see docs/specs/content-reports.md §3
 *
 * @api
 */
#[ORM\Entity]
#[ORM\Table(name: 'content_report')]
#[ORM\Index(name: 'idx_content_report_status', columns: ['status', 'created_at'])]
#[ORM\Index(name: 'idx_content_report_target', columns: ['target_type', 'target_id'])]
class ContentReport
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(name: 'target_type', length: 16, enumType: ReportTarget::class)]
    private ReportTarget $targetType;

    /** The id within its own table. String, because riders are addressed by uuid. */
    #[ORM\Column(name: 'target_id', length: 64)]
    private string $targetId;

    /** What the thing said when it was reported. @see class docblock */
    #[ORM\Column(name: 'target_label', type: Types::TEXT, nullable: true)]
    private ?string $targetLabel = null;

    #[ORM\Column(length: 32, enumType: ReportGround::class)]
    private ReportGround $ground;

    #[ORM\Column(type: Types::TEXT)]
    private string $reason;

    /**
     * Where to send the acknowledgement and the decision, if they gave one.
     *
     * Optional, because Article 16 does not let us require an address, and a
     * report from somebody who wants nothing back is still a valid report.
     */
    #[ORM\Column(name: 'reporter_contact', length: 180, nullable: true)]
    private ?string $reporterContact = null;

    /** Salted one-way hash. Never the address itself. */
    #[ORM\Column(name: 'from_path', length: 500, nullable: true)]
    private ?string $fromPath = null;

    #[ORM\Column(name: 'reporter_key', length: 64)]
    private string $reporterKey;

    #[ORM\Column(length: 16, enumType: ReportStatus::class)]
    private ReportStatus $status = ReportStatus::Open;

    /** Why the curator decided what they decided. Becomes the statement of reasons. */
    #[ORM\Column(name: 'decision_note', type: Types::TEXT, nullable: true)]
    private ?string $decisionNote = null;

    #[ORM\Column(name: 'decided_by_id', type: Types::INTEGER, nullable: true)]
    private ?int $decidedById = null;

    #[ORM\Column(name: 'decided_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    /** Set once the author has been told, so a re-run cannot tell them twice. */
    #[ORM\Column(name: 'author_told_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $authorToldAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /**
     * Where the original work is, for a `copyright` report only.
     *
     * A URL if it was ever online, a description of the work if it was not.
     * Free text on purpose: a rights holder should not have to own a website
     * to say "it is the print hanging in my hallway, shot on 12 June 2019".
     */
    #[ORM\Column(name: 'work_original', type: Types::TEXT, nullable: true)]
    private ?string $workOriginal = null;

    /**
     * The name the rights claim is made under, for a `copyright` report only.
     *
     * Not the same as the reporter's address, which nobody sees: this one IS
     * shown to the uploader in the Article 17 statement, because a person
     * cannot answer a claim without knowing who is making it. The form says so
     * at the field.
     */
    #[ORM\Column(name: 'claimant_name', length: 180, nullable: true)]
    private ?string $claimantName = null;

    /** What the uploader said back, once, after the Article 17 statement. */
    #[ORM\Column(name: 'counter_notice', type: Types::TEXT, nullable: true)]
    private ?string $counterNotice = null;

    #[ORM\Column(name: 'counter_notice_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $counterNoticeAt = null;

    public function __construct(
        Uuid $id,
        ReportTarget $targetType,
        string $targetId,
        ReportGround $ground,
        string $reason,
        string $reporterKey,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = $id;
        $this->targetType = $targetType;
        $this->targetId = $targetId;
        $this->ground = $ground;
        $this->reason = $reason;
        $this->reporterKey = $reporterKey;
        $this->createdAt = $createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTargetType(): ReportTarget
    {
        return $this->targetType;
    }

    public function getTargetId(): string
    {
        return $this->targetId;
    }

    public function getTargetLabel(): ?string
    {
        return $this->targetLabel;
    }

    public function setTargetLabel(?string $label): void
    {
        $this->targetLabel = $label;
    }

    public function getGround(): ReportGround
    {
        return $this->ground;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getReporterContact(): ?string
    {
        return $this->reporterContact;
    }

    public function setReporterContact(?string $contact): void
    {
        $this->reporterContact = $contact;
    }

    /**
     * The keyed hash of the reporter's address.
     *
     * Exposed so a curator query and a test can both check that this is what
     * is stored and the address itself is not. There is no setter: it is
     * computed once, at filing, and a report whose key could be changed would
     * not be evidence of anything.
     */
    public function getReporterKey(): string
    {
        return $this->reporterKey;
    }

    /**
     * The page the reporter was looking at when they clicked.
     *
     * Carried by the link rather than typed, so one wording ("Report this
     * page") works everywhere including the map. A curator opening the report
     * lands on what the person actually saw, which for a map view is the only
     * way to know which view they meant.
     */
    public function getFromPath(): ?string
    {
        return $this->fromPath;
    }

    public function setFromPath(?string $path): void
    {
        $path = null === $path ? null : trim($path);
        $this->fromPath = null !== $path && '' !== $path ? mb_substr($path, 0, 500) : null;
    }

    public function getStatus(): ReportStatus
    {
        return $this->status;
    }

    public function getDecisionNote(): ?string
    {
        return $this->decisionNote;
    }

    public function getDecidedById(): ?int
    {
        return $this->decidedById;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Record a curator's decision.
     *
     * The note is required for every outcome, including "nothing was wrong":
     * Article 17 wants reasons, and a reporter told only "rejected" learns
     * nothing and reports again.
     */
    public function decide(ReportStatus $status, string $note, int $curatorId, \DateTimeImmutable $at): void
    {
        $this->status = $status;
        $this->decisionNote = $note;
        $this->decidedById = $curatorId;
        $this->decidedAt = $at;
    }

    public function getWorkOriginal(): ?string
    {
        return $this->workOriginal;
    }

    public function getClaimantName(): ?string
    {
        return $this->claimantName;
    }

    /** Set together: a rights claim without either is not one. */
    public function setRightsClaim(?string $workOriginal, ?string $claimantName): void
    {
        $this->workOriginal = $workOriginal;
        $this->claimantName = $claimantName;
    }

    public function getCounterNotice(): ?string
    {
        return $this->counterNotice;
    }

    public function getCounterNoticeAt(): ?\DateTimeImmutable
    {
        return $this->counterNoticeAt;
    }

    public function hasCounterNotice(): bool
    {
        return null !== $this->counterNoticeAt;
    }

    /**
     * The uploader answers the claim, once.
     *
     * Once, because the link that reaches this is in a mail that was sent once,
     * and a second answer would be a conversation this desk cannot hold. A
     * curator who needs more asks through the normal message thread.
     */
    public function recordCounterNotice(string $text, \DateTimeImmutable $at): void
    {
        if (null !== $this->counterNoticeAt) {
            return;
        }
        $this->counterNotice = $text;
        $this->counterNoticeAt = $at;
    }

    public function isAuthorTold(): bool
    {
        return null !== $this->authorToldAt;
    }

    public function markAuthorTold(\DateTimeImmutable $at): void
    {
        $this->authorToldAt = $at;
    }
}
