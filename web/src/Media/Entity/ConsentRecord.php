<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One consent act. Append-only; user_id is not a cascading FK.
 *
 * @see docs/specs/photo-uploads.md §3
 *
 * @api
 */
#[ORM\Entity]
#[ORM\Table(name: 'consent_record')]
#[ORM\Index(name: 'idx_consent_user_kind', columns: ['user_id', 'kind', 'version'])]
class ConsentRecord
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(name: 'user_id', type: Types::BIGINT)]
    private int $userId;

    /** {@see \App\Media\MediaConsent::KIND} */
    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $kind;

    /** Wording version; a change here brings the modal back. */
    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $version;

    /** sha256 of the exact text the rider was shown. */
    #[ORM\Column(name: 'text_hash', type: Types::STRING, length: 64)]
    private string $textHash;

    #[ORM\Column(name: 'consented_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $consentedAt;

    public function __construct(Uuid $id, int $userId, string $kind, string $version, string $textHash)
    {
        $this->id = $id;
        $this->userId = $userId;
        $this->kind = $kind;
        $this->version = $version;
        $this->textHash = $textHash;
        $this->consentedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getTextHash(): string
    {
        return $this->textHash;
    }

    public function getConsentedAt(): \DateTimeImmutable
    {
        return $this->consentedAt;
    }
}
