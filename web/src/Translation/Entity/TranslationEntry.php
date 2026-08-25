<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Translation\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One catalogue key. English is a projection of messages.en.yaml, not a second source.
 *
 * Identity is message_key — never display wording.
 *
 * @see docs/specs/translations.md §3.1, §3.2
 *
 * @api
 */
#[ORM\Entity]
#[ORM\Table(name: 'translation_entry')]
#[ORM\UniqueConstraint(name: 'uniq_translation_entry_message_key', columns: ['message_key'])]
class TranslationEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(name: 'message_key', type: Types::STRING, length: 255)]
    private string $messageKey;

    #[ORM\Column(type: Types::TEXT)]
    private string $english;

    #[ORM\Column(name: 'synced_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $syncedAt;

    #[ORM\Column(name: 'absent_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $absentAt = null;

    public function __construct(string $messageKey, string $english)
    {
        $this->messageKey = $messageKey;
        $this->english = $english;
        $this->syncedAt = new \DateTimeImmutable();
    }

    public function markAbsent(): void
    {
        $this->absentAt = new \DateTimeImmutable();
        $this->syncedAt = new \DateTimeImmutable();
    }

    public function restoreFromYaml(string $english): void
    {
        $this->english = $english;
        $this->absentAt = null;
        $this->syncedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMessageKey(): string
    {
        return $this->messageKey;
    }

    public function getEnglish(): string
    {
        return $this->english;
    }

    public function getSyncedAt(): \DateTimeImmutable
    {
        return $this->syncedAt;
    }

    public function getAbsentAt(): ?\DateTimeImmutable
    {
        return $this->absentAt;
    }
}
