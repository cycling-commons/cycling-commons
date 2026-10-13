<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Roadmap\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A roadmap item's words in one language: a short title, shown bold, and
 * the sentence or two after it. Plain text; the page escapes it.
 *
 * @see docs/specs/roadmap-and-changelog.md §8
 *
 * @api
 */
#[ORM\Entity]
#[ORM\Table(name: 'roadmap_item_text')]
#[ORM\UniqueConstraint(name: 'uniq_roadmap_item_text', columns: ['item_id', 'locale'])]
class RoadmapItemText
{
    public const int TITLE_MAX = 120;
    public const int BODY_MAX = 4000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: RoadmapItem::class, inversedBy: 'texts')]
    #[ORM\JoinColumn(name: 'item_id', nullable: false, onDelete: 'CASCADE')]
    private RoadmapItem $item;

    #[ORM\Column(type: Types::STRING, length: 8)]
    private string $locale;

    #[ORM\Column(type: Types::STRING, length: self::TITLE_MAX)]
    private string $title;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    public function __construct(RoadmapItem $item, string $locale, string $title, string $body)
    {
        $this->item = $item;
        $this->locale = $locale;
        $this->title = $title;
        $this->body = $body;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getItem(): RoadmapItem
    {
        return $this->item;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function change(string $title, string $body): void
    {
        $this->title = $title;
        $this->body = $body;
    }
}
