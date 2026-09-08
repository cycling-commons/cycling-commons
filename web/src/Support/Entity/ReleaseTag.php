<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Support\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A release the software has been tagged with, as the bugs desk knows it.
 *
 * The desk's "Fixed in" used to be free text (owner 2026-09-08: "Fixed in
 * should be a dropdown with release tags, release tags can be added in the
 * admin section"). One row per tag, kept by an admin at /admin; the desk
 * offers them and accepts nothing else. The changelog itself stays in code
 * (`ReleaseNotes`), because its entries are translated copy; this table is
 * the list of names, nothing more.
 *
 * @see docs/specs/contact-and-support.md §9
 *
 * @api
 */
#[ORM\Entity]
#[ORM\Table(name: 'release_tag')]
#[ORM\UniqueConstraint(name: 'uniq_release_tag_tag', columns: ['tag'])]
class ReleaseTag
{
    public const int TAG_MAX = 64;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    /** The git tag, with its leading v: `v0.8.0-beta`. */
    #[ORM\Column(length: self::TAG_MAX)]
    private string $tag = '';

    #[ORM\Column(name: 'released_at', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $releasedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTag(): string
    {
        return $this->tag;
    }

    public function setTag(string $tag): static
    {
        $this->tag = mb_substr(trim($tag), 0, self::TAG_MAX);

        return $this;
    }

    public function getReleasedAt(): ?\DateTimeImmutable
    {
        return $this->releasedAt;
    }

    public function setReleasedAt(?\DateTimeImmutable $releasedAt): static
    {
        $this->releasedAt = $releasedAt;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = null === $note || '' === trim($note) ? null : trim($note);

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function __toString(): string
    {
        return $this->tag;
    }
}
