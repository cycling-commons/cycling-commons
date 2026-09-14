<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Community\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Where somebody wants the Commons to reach: a country, or an area inside one.
 *
 * **Why the area is free text.** The whole point of the signal is somewhere
 * the Commons does NOT cover, so there is no `region` row to point a foreign
 * key at. A rider in Texas is asking for a region that does not exist yet, and
 * a picker of existing regions cannot express that (owner 2026-09-13: "Or
 * region. f.e. USA we have states at the region level"). What a curator needs
 * from this is a name and a count, and text carries both.
 *
 * **Why it is stored empty rather than null.** Empty means "the whole
 * country", and it is a value the unique constraint can compare. Postgres
 * treats two NULLs as distinct, so a nullable column would let one person file
 * the same country twice and the count would stop being a count of people.
 *
 * @see docs/specs/moderation-and-contribution.md §11
 *
 * @api
 */
#[ORM\Entity]
#[ORM\Table(name: 'country_interest')]
#[ORM\UniqueConstraint(name: 'uniq_country_interest_user_cc', columns: ['user_id', 'country_code', 'region_name'])]
class CountryInterest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(name: 'user_id', type: Types::BIGINT)]
    private int $userId;

    #[ORM\Column(name: 'country_code', type: Types::STRING, length: 2)]
    private string $countryCode;

    /** The area they named, or '' for the country as a whole. */
    #[ORM\Column(name: 'region_name', type: Types::STRING, length: 120, options: ['default' => ''])]
    private string $regionName = '';

    #[ORM\Column(name: 'willing_to_curate', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $willingToCurate = false;

    #[ORM\Column(type: Types::STRING, length: 280, nullable: true)]
    private ?string $note = null;

    /**
     * @psalm-suppress UnusedProperty
     */
    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @psalm-suppress UnusedProperty
     */
    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(int $userId, string $countryCode, string $regionName = '')
    {
        $this->userId = $userId;
        $this->countryCode = $countryCode;
        $this->regionName = $regionName;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
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

    public function getRegionName(): string
    {
        return $this->regionName;
    }

    public function setRegionName(string $name): void
    {
        $this->regionName = $name;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isWillingToCurate(): bool
    {
        return $this->willingToCurate;
    }

    public function setWillingToCurate(bool $v): void
    {
        $this->willingToCurate = $v;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): void
    {
        $this->note = $note;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
