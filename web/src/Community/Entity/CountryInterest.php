<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Community\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * "I want Cycling Commons here" — a tick against a country code, existing to be
 * counted so the next country to onboard is chosen from evidence.
 *
 * Deliberately NOT tied to a region: 245 countries have none, and requiring one
 * would make the signal impossible exactly where it matters most (§4).
 *
 * @api Written by CountryInterestService::record(); read today only through
 *      its own `counts()` aggregate query (§12.2's owner-only-for-v1 counter
 *      is not wired to a page yet). Per-row accessors are Doctrine hydration
 *      + entity-completeness, exercised directly by CountryInterestTest.
 */
#[ORM\Entity]
#[ORM\Table(name: 'country_interest')]
#[ORM\UniqueConstraint(name: 'uniq_country_interest_user_cc', columns: ['user_id', 'country_code'])]
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

    #[ORM\Column(name: 'willing_to_curate', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $willingToCurate = false;

    #[ORM\Column(type: Types::STRING, length: 280, nullable: true)]
    private ?string $note = null;

    /**
     * @psalm-suppress UnusedProperty Doctrine-mapped column; no reader yet
     *                                (see the class docblock).
     */
    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @psalm-suppress UnusedProperty Doctrine-mapped column; no reader yet
     *                                (see the class docblock).
     */
    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(int $userId, string $countryCode)
    {
        $this->userId = $userId;
        $this->countryCode = $countryCode;
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
