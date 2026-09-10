<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\World\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Continent. Seeded from a static list of seven codes.
 *
 * @api
 */
#[ORM\Entity]
#[ORM\Table(name: 'world_continent')]
class Continent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /** Two-letter continent code, e.g. EU, AF, AS, NA, SA, OC, AN. */
    #[ORM\Column(type: 'string', length: 2, unique: true)]
    private string $code = '';

    #[ORM\Column(type: 'string', length: 64)]
    private string $name = '';

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
        $this->code = $code;

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
}
