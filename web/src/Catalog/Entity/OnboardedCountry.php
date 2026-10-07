<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One onboarded country: planned, then seeded (its region rows exist, so the harvest may run), then live.
 *
 * timezones is migration-only (TEXT[], like region.adj): CountryTimezones and Countries read and write it with DBAL.
 *
 * @see wiki/developers/data-ops/onboarding-a-country.md
 *
 * @api
 */
#[ORM\Entity]
#[ORM\Table(name: 'country')]
class OnboardedCountry
{
    /** ISO 3166-1 alpha-2. */
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 2, options: ['fixed' => true])]
    private string $code = '';

    #[ORM\Column(type: 'string', length: 100)]
    private string $name = '';

    /** Overture subtype the regions were seeded at: region, county, country. */
    #[ORM\Column(type: 'string', length: 20)]
    private string $subtype = '';

    /** @var list<float>|null [west, south, east, north] */
    #[ORM\Column(type: 'json', nullable: true, options: ['jsonb' => true])]
    private ?array $bbox = null;

    /** @var array<string, string> locale => "All <country>" phrase */
    #[ORM\Column(type: 'json', options: ['jsonb' => true, 'default' => '{}'])]
    private array $labels = [];

    /** planned | seeded | live (App\Onboarding\CountryStatus). */
    #[ORM\Column(type: 'string', length: 10)]
    private string $status = 'planned';

    #[ORM\Column(type: 'string', length: 40, nullable: true)]
    private ?string $overtureRelease = null;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $plannedAt = null;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $seededAt = null;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $liveAt = null;

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSubtype(): string
    {
        return $this->subtype;
    }

    /** @return list<float>|null */
    public function getBbox(): ?array
    {
        return $this->bbox;
    }

    /** @return array<string, string> */
    public function getLabels(): array
    {
        return $this->labels;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getOvertureRelease(): ?string
    {
        return $this->overtureRelease;
    }

    public function getPlannedAt(): ?\DateTimeImmutable
    {
        return $this->plannedAt;
    }

    public function getSeededAt(): ?\DateTimeImmutable
    {
        return $this->seededAt;
    }

    public function getLiveAt(): ?\DateTimeImmutable
    {
        return $this->liveAt;
    }
}
