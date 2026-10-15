<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Map\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One set of map switches a rider saved under a name (docs/specs/map-presets.md
 * section 1 and 2): the view mode, the layers and overlays that are on, the
 * Best of season and bike chips, and the per-layer facet chips. Which tools
 * use it lives in `map_preset_use` ({@see \App\Map\MapPresets}).
 *
 * A preset is its rider's alone: no curator, public page, API or tile reads
 * it, and it goes with the account (`ON DELETE CASCADE` in the schema).
 *
 * @api
 */
#[ORM\Entity]
#[ORM\Table(name: 'map_preset')]
#[ORM\Index(name: 'idx_map_preset_user', columns: ['user_id', 'created_at'])]
class MapPreset
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(name: 'user_id', type: Types::BIGINT)]
    private int $userId;

    #[ORM\Column(type: Types::STRING, length: 40)]
    private string $name = '';

    /**
     * The switches, as {@see \App\Map\PresetInput} normalises them.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $settings = [];

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(int $userId)
    {
        $this->userId = $userId;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    /** @param array<string, mixed> $settings */
    public function fill(string $name, array $settings): static
    {
        $this->name = $name;
        $this->settings = $settings;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** @return array<string, mixed> */
    public function getSettings(): array
    {
        return $this->settings;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
