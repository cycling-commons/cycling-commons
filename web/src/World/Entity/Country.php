<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\World\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * ISO 3166-1 country. Names from symfony/intl; continent from a static map.
 *
 * @api
 */
#[ORM\Entity]
#[ORM\Table(name: 'world_country')]
class Country
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /** ISO 3166-1 alpha-2, e.g. BE. */
    #[ORM\Column(type: 'string', length: 2, unique: true)]
    private string $iso2 = '';

    /** ISO 3166-1 alpha-3, e.g. BEL. */
    #[ORM\Column(type: 'string', length: 3)]
    private string $iso3 = '';

    #[ORM\Column(type: 'string', length: 120)]
    private string $name = '';

    #[ORM\ManyToOne(targetEntity: Continent::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Continent $continent = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIso2(): string
    {
        return $this->iso2;
    }

    public function setIso2(string $iso2): static
    {
        $this->iso2 = strtoupper($iso2);

        return $this;
    }

    public function getIso3(): string
    {
        return $this->iso3;
    }

    public function setIso3(string $iso3): static
    {
        $this->iso3 = strtoupper($iso3);

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

    public function getContinent(): ?Continent
    {
        return $this->continent;
    }

    public function setContinent(?Continent $continent): static
    {
        $this->continent = $continent;

        return $this;
    }

    /** Logical asset path for this country's flag, e.g. "flags/be.svg". */
    public function flagAsset(): string
    {
        return 'flags/'.strtolower($this->iso2).'.svg';
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
