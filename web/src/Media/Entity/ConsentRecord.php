<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One consent act: a rider ticked the licence contract at a point in time.
 * Append-only and immutable — no setters exist, and no code path may update or
 * delete a row. The licence grant outlives the account that made it, which is
 * why user_id is a plain column and not a cascading foreign key
 * (docs/specs/photo-uploads.md §3 consent ledger).
 *
 * When the phase-2 write API lands (docs/specs/public-api.md §8), external
 * consent rows key on api_app_id + external_author_ref instead of user_id,
 * which becomes nullable behind an exactly-one-origin CHECK. Nothing here may
 * assume user_id is permanently NOT NULL.
 *
 * @api Media domain entity; referenced by every media_upload row.
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

    /** {@see \App\Media\MediaConsent::KIND} — which contract was granted. */
    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $kind;

    /** Wording version; a change here is what brings the modal back. */
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
