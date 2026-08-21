<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\World\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * ISO 3166-2 subdivision. Self-referencing `parent` models country-specific depth.
 *
 * @api
 */
#[ORM\Entity]
#[ORM\Table(name: 'world_subdivision')]
#[ORM\Index(name: 'idx_subdivision_country', columns: ['country_id'])]
class Subdivision
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /** ISO 3166-2 code, e.g. BE-VAN, US-CA. */
    #[ORM\Column(type: 'string', length: 10, unique: true)]
    private string $code = '';

    #[ORM\Column(type: 'string', length: 160)]
    private string $name = '';

    /** ISO 3166-2 subdivision category, e.g. Province, Region, State, County. */
    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $type = null;

    #[ORM\ManyToOne(targetEntity: Country::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Country $country = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?self $parent = null;

    /** Depth in the subdivision tree: 1 = top-level, 2 = below that, … */
    #[ORM\Column(type: 'smallint', options: ['default' => 1])]
    private int $level = 1;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = strtoupper($code);

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getCountry(): ?Country
    {
        return $this->country;
    }

    public function setCountry(?Country $country): static
    {
        $this->country = $country;

        return $this;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): static
    {
        $this->parent = $parent;

        return $this;
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function setLevel(int $level): static
    {
        $this->level = $level;

        return $this;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
