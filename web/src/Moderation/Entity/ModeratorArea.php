<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Moderation\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One area assignment: a user + either a region or a country. No rows = global.
 *
 * @see docs/specs/moderation-and-contribution.md §9.1
 *
 * @api
 */
#[ORM\Entity]
#[ORM\Table(name: 'moderator_area')]
#[ORM\UniqueConstraint(name: 'uniq_moderator_area', columns: ['user_id', 'region_id', 'country_code'])]
#[ORM\Index(name: 'idx_moderator_area_user', columns: ['user_id'])]
class ModeratorArea
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(name: 'user_id', type: Types::BIGINT)]
    private int $userId;

    #[ORM\Column(name: 'region_id', type: Types::BIGINT, nullable: true)]
    private ?int $regionId;

    #[ORM\Column(name: 'country_code', type: Types::STRING, length: 2, nullable: true)]
    private ?string $countryCode;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(int $userId, ?int $regionId, ?string $countryCode)
    {
        if (null !== $countryCode) {
            $countryCode = trim($countryCode);
            if (2 !== \strlen($countryCode)) {
                throw new \InvalidArgumentException('A country code must be a 2-letter ISO code.');
            }
        }
        if ((null === $regionId) === (null === $countryCode)) {
            throw new \InvalidArgumentException('A moderator area is exactly one region OR one country.');
        }
        $this->userId = $userId;
        $this->regionId = $regionId;
        $this->countryCode = null !== $countryCode ? strtoupper($countryCode) : null;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getRegionId(): ?int
    {
        return $this->regionId;
    }

    public function getCountryCode(): ?string
    {
        return $this->countryCode;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
