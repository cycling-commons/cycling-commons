<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A confirmed onboarding plan row before it becomes a region row. fallback_locales is migration-only (TEXT[]).
 *
 * @api
 */
#[ORM\Entity]
#[ORM\Table(name: 'country_plan_region')]
class CountryPlanRegion
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 2, options: ['fixed' => true])]
    private string $countryCode = '';

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 80)]
    private string $slug = '';

    #[ORM\Column(type: 'string', length: 10, nullable: true)]
    private ?string $isoCode = null;

    #[ORM\Column(type: 'string', length: 160)]
    private string $name = '';

    /** @var array<string, string> */
    #[ORM\Column(type: 'json', options: ['jsonb' => true, 'default' => '{}'])]
    private array $labels = [];

    #[ORM\Column(type: 'smallint')]
    private int $adminLevel = 0;

    #[ORM\Column(type: 'float')]
    private float $areaKm2 = 0.0;

    #[ORM\Column(type: 'geometry')]
    private ?string $geom = null;

    public function getCountryCode(): string
    {
        return $this->countryCode;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getIsoCode(): ?string
    {
        return $this->isoCode;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** @return array<string, string> */
    public function getLabels(): array
    {
        return $this->labels;
    }

    public function getAdminLevel(): int
    {
        return $this->adminLevel;
    }

    public function getAreaKm2(): float
    {
        return $this->areaKm2;
    }

    public function getGeom(): ?string
    {
        return $this->geom;
    }
}
