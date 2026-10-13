<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Roadmap\Entity;

use App\Content\ReleaseNotes;
use App\Roadmap\ItemState;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One item on the roadmap: a group (`now`, `next`, `later`), a place in
 * that group, its words in each language, and whether it is still on the
 * roadmap.
 *
 * Written on the roadmap desk, so moving, adding or rewording an item needs
 * no deploy. An item that shipped or was dropped keeps its row with its
 * state and `closed_at`: a promoted proposal still points at it, and the
 * desk can see what left and when.
 *
 * @see docs/specs/roadmap-and-changelog.md §8
 *
 * @api
 */
#[ORM\Entity]
#[ORM\Table(name: 'roadmap_item')]
#[ORM\Index(name: 'idx_roadmap_item_list', columns: ['state', 'status', 'position'])]
class RoadmapItem
{
    /** The prefix of an item's `item_ref` on votes and follows. */
    public const string REF_PREFIX = 'item:';

    /** The language a missing translation falls back to first. */
    public const string FALLBACK_LOCALE = 'en';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    /** `now`, `next` or `later` (ReleaseNotes::STATUSES). */
    #[ORM\Column(type: Types::STRING, length: 8)]
    private string $status;

    #[ORM\Column(type: Types::STRING, length: 8, enumType: ItemState::class)]
    private ItemState $state = ItemState::Live;

    /** The order inside the group, lowest first; gaps are fine. */
    #[ORM\Column(type: Types::INTEGER)]
    private int $position;

    /** A GitHub issue number the page links, if any. */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $issue = null;

    /** The rider's proposal this item was promoted from, if it was. */
    #[ORM\ManyToOne(targetEntity: RoadmapProposal::class)]
    #[ORM\JoinColumn(name: 'source_proposal_id', nullable: true, onDelete: 'SET NULL')]
    private ?RoadmapProposal $sourceProposal = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'closed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    /** @var Collection<string, RoadmapItemText> keyed by locale */
    #[ORM\OneToMany(mappedBy: 'item', targetEntity: RoadmapItemText::class, cascade: ['persist', 'remove'], orphanRemoval: true, indexBy: 'locale')]
    private Collection $texts;

    public function __construct(string $status, int $position)
    {
        \assert(\in_array($status, ReleaseNotes::STATUSES, true));
        $this->status = $status;
        $this->position = $position;
        $this->texts = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /** The name votes and follows use for this item. */
    public function getRef(): string
    {
        return self::REF_PREFIX.($this->id ?? 0);
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getState(): ItemState
    {
        return $this->state;
    }

    public function isLive(): bool
    {
        return ItemState::Live === $this->state;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getIssue(): ?int
    {
        return $this->issue;
    }

    public function setIssue(?int $issue): void
    {
        $this->issue = $issue;
        $this->touch();
    }

    public function getSourceProposal(): ?RoadmapProposal
    {
        return $this->sourceProposal;
    }

    public function setSourceProposal(?RoadmapProposal $proposal): void
    {
        $this->sourceProposal = $proposal;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function placeIn(string $status, int $position): void
    {
        \assert(\in_array($status, ReleaseNotes::STATUSES, true));
        $this->status = $status;
        $this->position = $position;
        $this->touch();
    }

    public function close(ItemState $state): void
    {
        \assert(ItemState::Live !== $state);
        $this->state = $state;
        $this->closedAt = new \DateTimeImmutable();
        $this->touch();
    }

    /** @return array<string, RoadmapItemText> keyed by locale */
    public function getTexts(): array
    {
        return $this->texts->toArray();
    }

    public function textIn(string $locale): ?RoadmapItemText
    {
        return $this->texts->get($locale);
    }

    /**
     * The words to show in a language: that language's text, else the
     * fallback language's, else whichever text there is. Null only for an
     * item without any text, which the desk does not let happen.
     */
    public function textFor(string $locale): ?RoadmapItemText
    {
        return $this->texts->get($locale)
            ?? $this->texts->get(self::FALLBACK_LOCALE)
            ?? ($this->texts->first() ?: null);
    }

    /** Sets one language's words; an empty title removes that language. */
    public function setText(string $locale, string $title, string $body): void
    {
        $existing = $this->texts->get($locale);
        if ('' === $title) {
            if (null !== $existing) {
                $this->texts->remove($locale);
            }
        } elseif (null !== $existing) {
            $existing->change($title, $body);
        } else {
            $this->texts->set($locale, new RoadmapItemText($this, $locale, $title, $body));
        }
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
