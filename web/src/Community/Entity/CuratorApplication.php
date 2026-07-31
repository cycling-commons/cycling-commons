<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Community\Entity;

use App\Community\CuratorApplicationStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * "I'd like to curate here".
 *
 * Evidence is deliberately NOT copied into this row: the applicant's
 * submissions are looked up by user + country at review time, so the reviewer
 * always sees their current state rather than a snapshot taken at submit (§8).
 *
 * @api Written by CuratorApplicationService; the OSM/about/scope accessors
 *      are read by admin/curator_applications.html.twig (§9) and covered
 *      directly by CuratorApplicationTest. `decidedBy`/`decidedAt` are set by
 *      decide() but have no reader yet — `AdminActionLogger`'s row already
 *      carries actor + timestamp for the audit trail (§4.1/§6.2 of
 *      account-and-auth.md), so nothing has needed to read them back off this
 *      row; `getCreatedAt()` mirrors `Submission`'s decision-column shape and
 *      is not yet surfaced on the review page.
 */
#[ORM\Entity]
#[ORM\Table(name: 'curator_application')]
#[ORM\Index(name: 'idx_curator_application_status', columns: ['status'])]
class CuratorApplication
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(name: 'user_id', type: Types::BIGINT)]
    private int $userId;

    #[ORM\Column(name: 'country_code', type: Types::STRING, length: 2)]
    private string $countryCode;

    /** null = the whole country; set = one division (§4 step 4). */
    #[ORM\Column(name: 'requested_region_id', type: Types::BIGINT, nullable: true)]
    private ?int $requestedRegionId = null;

    #[ORM\Column(name: 'osm_username', type: Types::STRING, length: 64, nullable: true)]
    private ?string $osmUsername = null;

    #[ORM\Column(name: 'osm_verified_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $osmVerifiedAt = null;

    /** null = never checked or OSM was unreachable; true = found; false = checked and not found. */
    #[ORM\Column(name: 'osm_exists', type: Types::BOOLEAN, nullable: true)]
    private ?bool $osmExists = null;

    #[ORM\Column(name: 'osm_changeset_count', type: Types::INTEGER, nullable: true)]
    private ?int $osmChangesetCount = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $about = '';

    /**
     * Optional "where can we find you online" link — a normalized http(s)
     * URL, validated by CuratorApplicationService. Reviewer-only, like the
     * OSM handle; never rendered publicly.
     */
    #[ORM\Column(name: 'social_url', type: Types::STRING, length: 255, nullable: true)]
    private ?string $socialUrl = null;

    #[ORM\Column(type: Types::STRING, length: 12, enumType: CuratorApplicationStatus::class)]
    private CuratorApplicationStatus $status = CuratorApplicationStatus::Pending;

    /**
     * @psalm-suppress UnusedProperty Set by decide(); no reader yet (see the
     *                                class docblock).
     */
    #[ORM\Column(name: 'decided_by', type: Types::BIGINT, nullable: true)]
    private ?int $decidedBy = null;

    /**
     * @psalm-suppress UnusedProperty Set by decide(); no reader yet (see the
     *                                class docblock).
     */
    #[ORM\Column(name: 'decided_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    #[ORM\Column(name: 'decision_note', type: Types::TEXT, nullable: true)]
    private ?string $decisionNote = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(int $userId, string $countryCode)
    {
        $this->userId = $userId;
        $this->countryCode = $countryCode;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getCountryCode(): string
    {
        return $this->countryCode;
    }

    public function getRequestedRegionId(): ?int
    {
        return $this->requestedRegionId;
    }

    public function setRequestedRegionId(?int $id): void
    {
        $this->requestedRegionId = $id;
    }

    public function getOsmUsername(): ?string
    {
        return $this->osmUsername;
    }

    public function setOsmUsername(?string $u): void
    {
        $this->osmUsername = $u;
    }

    public function getOsmVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->osmVerifiedAt;
    }

    public function setOsmVerifiedAt(?\DateTimeImmutable $t): void
    {
        $this->osmVerifiedAt = $t;
    }

    public function getOsmExists(): ?bool
    {
        return $this->osmExists;
    }

    public function setOsmExists(?bool $exists): void
    {
        $this->osmExists = $exists;
    }

    public function getOsmChangesetCount(): ?int
    {
        return $this->osmChangesetCount;
    }

    public function setOsmChangesetCount(?int $n): void
    {
        $this->osmChangesetCount = $n;
    }

    public function getAbout(): string
    {
        return $this->about;
    }

    public function setAbout(string $about): void
    {
        $this->about = $about;
    }

    public function getSocialUrl(): ?string
    {
        return $this->socialUrl;
    }

    public function setSocialUrl(?string $url): void
    {
        $this->socialUrl = $url;
    }

    public function getStatus(): CuratorApplicationStatus
    {
        return $this->status;
    }

    public function decide(CuratorApplicationStatus $status, int $actorId, ?string $note): void
    {
        $this->status = $status;
        $this->decidedBy = $actorId;
        $this->decidedAt = new \DateTimeImmutable();
        $this->decisionNote = $note;
    }

    public function getDecisionNote(): ?string
    {
        return $this->decisionNote;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
