<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Roadmap\Entity;

use App\Roadmap\ProposalStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A rider's proposal for the roadmap, as they wrote it, and what the
 * roadmap desk decided about it.
 *
 * Three questions shape it, the same three a good roadmap item answers: what
 * is missing or hard today (`problem`), what the rider would like instead
 * (`idea`), and who gains (`benefit`, optional). A proposal the desk opens
 * for votes shows on the Proposals tab under its `public_title` and
 * `public_body` when the desk wrote them, else the rider's own words, and
 * never the rider's name. Promoted, it becomes a roadmap item
 * (`promoted_item_id`).
 *
 * @see docs/specs/roadmap-and-changelog.md §8
 *
 * @api
 */
#[ORM\Entity]
#[ORM\Table(name: 'roadmap_proposal')]
#[ORM\Index(name: 'idx_roadmap_proposal_status', columns: ['status', 'created_at'])]
#[ORM\Index(name: 'idx_roadmap_proposal_user', columns: ['user_id', 'created_at'])]
class RoadmapProposal
{
    public const int TITLE_MAX = 120;
    public const int TEXT_MAX = 4000;
    public const int BENEFIT_MAX = 2000;

    /** The prefix of an idea's `item_ref` on votes and follows. */
    public const string REF_PREFIX = 'proposal:';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    /** Null once the account is gone; an idea on the roadmap stays there. */
    #[ORM\Column(name: 'user_id', type: Types::BIGINT, nullable: true)]
    private ?int $userId;

    #[ORM\Column(type: Types::STRING, length: self::TITLE_MAX)]
    private string $title;

    #[ORM\Column(type: Types::TEXT)]
    private string $problem;

    #[ORM\Column(type: Types::TEXT)]
    private string $idea;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $benefit;

    /** The language the rider wrote in, so the desk knows what it is reading. */
    #[ORM\Column(type: Types::STRING, length: 8)]
    private string $locale;

    #[ORM\Column(type: Types::STRING, length: 12, enumType: ProposalStatus::class)]
    private ProposalStatus $status = ProposalStatus::Pending;

    /** The roadmap item it became, once promoted. */
    #[ORM\ManyToOne(targetEntity: RoadmapItem::class)]
    #[ORM\JoinColumn(name: 'promoted_item_id', nullable: true, onDelete: 'SET NULL')]
    private ?RoadmapItem $promotedItem = null;

    #[ORM\Column(name: 'public_title', type: Types::STRING, length: self::TITLE_MAX, nullable: true)]
    private ?string $publicTitle = null;

    #[ORM\Column(name: 'public_body', type: Types::TEXT, nullable: true)]
    private ?string $publicBody = null;

    /** What the proposer was told with the last decision; a decline always has one. */
    #[ORM\Column(name: 'decision_note', type: Types::TEXT, nullable: true)]
    private ?string $decisionNote = null;

    #[ORM\Column(name: 'decided_by', type: Types::BIGINT, nullable: true)]
    private ?int $decidedBy = null;

    #[ORM\Column(name: 'decided_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(int $userId, string $title, string $problem, string $idea, ?string $benefit, string $locale)
    {
        $this->userId = $userId;
        $this->title = $title;
        $this->problem = $problem;
        $this->idea = $idea;
        $this->benefit = $benefit;
        $this->locale = $locale;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /** The name votes and follows use for this idea. */
    public function getRef(): string
    {
        return self::REF_PREFIX.($this->id ?? 0);
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function forgetAuthor(): void
    {
        $this->userId = null;
        $this->touch();
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getProblem(): string
    {
        return $this->problem;
    }

    public function getIdea(): string
    {
        return $this->idea;
    }

    public function getBenefit(): ?string
    {
        return $this->benefit;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getStatus(): ProposalStatus
    {
        return $this->status;
    }

    /** Public on the Proposals tab, where riders vote and follow. */
    public function isOpen(): bool
    {
        return ProposalStatus::Open === $this->status;
    }

    public function getPromotedItem(): ?RoadmapItem
    {
        return $this->promotedItem;
    }

    /** What the Proposals tab calls it: the desk's wording if there is one. */
    public function getShownTitle(): string
    {
        return null !== $this->publicTitle && '' !== $this->publicTitle ? $this->publicTitle : $this->title;
    }

    /** What the Proposals tab says about it: the desk's wording, else the rider's idea. */
    public function getShownBody(): string
    {
        return null !== $this->publicBody && '' !== $this->publicBody ? $this->publicBody : $this->idea;
    }

    public function getPublicTitle(): ?string
    {
        return $this->publicTitle;
    }

    public function getPublicBody(): ?string
    {
        return $this->publicBody;
    }

    public function getDecisionNote(): ?string
    {
        return $this->decisionNote;
    }

    public function getDecidedBy(): ?int
    {
        return $this->decidedBy;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** Records a decision: the new status, the note the proposer was sent, who decided. */
    public function decide(ProposalStatus $status, ?string $note, int $decidedBy, ?RoadmapItem $promotedItem = null): void
    {
        \assert((null !== $promotedItem) === (ProposalStatus::Promoted === $status));
        $this->status = $status;
        $this->promotedItem = $promotedItem;
        $this->decisionNote = $note;
        $this->decidedBy = $decidedBy;
        $this->decidedAt = new \DateTimeImmutable();
        $this->touch();
    }

    public function setPublicWording(?string $title, ?string $body): void
    {
        $this->publicTitle = $title;
        $this->publicBody = $body;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
