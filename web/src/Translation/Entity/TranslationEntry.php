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

    #[ORM\Column(name: 'english_yaml', type: Types::TEXT)]
    private string $englishYaml;

    #[ORM\Column(name: 'english_version', type: Types::INTEGER)]
    private int $englishVersion = 1;

    #[ORM\Column(name: 'yaml_english_version', type: Types::INTEGER)]
    private int $yamlEnglishVersion = 1;

    #[ORM\Column(name: 'synced_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $syncedAt;

    #[ORM\Column(name: 'absent_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $absentAt = null;

    public function __construct(string $messageKey, string $english, \DateTimeImmutable $now = new \DateTimeImmutable())
    {
        $this->messageKey = $messageKey;
        $this->english = $english;
        $this->englishYaml = $english;
        $this->syncedAt = $now;
    }

    public function markAbsent(\DateTimeImmutable $now = new \DateTimeImmutable()): void
    {
        $this->absentAt = $now;
        $this->syncedAt = $now;
    }

    /** Git changed the wording (translations.md §3.3, "from git"). */
    public function applyGitEnglish(string $yaml, \DateTimeImmutable $now = new \DateTimeImmutable()): void
    {
        $this->englishYaml = $yaml;
        $this->english = $yaml;
        ++$this->englishVersion;
        $this->yamlEnglishVersion = $this->englishVersion;
        $this->absentAt = null;
        $this->syncedAt = $now;
    }

    /** Git now carries the live overlay wording (translations.md §3.3, "git catches up"). */
    public function absorbGitEnglish(string $yaml, \DateTimeImmutable $now = new \DateTimeImmutable()): void
    {
        $this->englishYaml = $yaml;
        $this->yamlEnglishVersion = $this->englishVersion;
        $this->absentAt = null;
        $this->syncedAt = $now;
    }

    /** A curator's English edit was approved (translations.md §3.3, "from an approve"). */
    public function applyApprovedEnglish(string $english): void
    {
        $this->english = $english;
        ++$this->englishVersion;
    }

    /** The sync saw this key and nothing moved. */
    public function touchSynced(\DateTimeImmutable $now = new \DateTimeImmutable()): void
    {
        $this->absentAt = null;
        $this->syncedAt = $now;
    }

    public function getEnglishYaml(): string
    {
        return $this->englishYaml;
    }

    public function getEnglishVersion(): int
    {
        return $this->englishVersion;
    }

    public function getYamlEnglishVersion(): int
    {
        return $this->yamlEnglishVersion;
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
