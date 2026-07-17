<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Entity;

use App\Repository\AdminActionLogRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One immutable record of an administrative action taken against an account.
 * `actor` is null for system-driven actions (e.g. the future inactivity
 * sweep). `targetUser` is nullable so a row outlives the account it
 * recorded the removal of (FK is ON DELETE SET NULL); the target's email is
 * snapshotted into `note`.
 *
 * @see docs/specs/account-and-auth.md §6.2
 *
 * @api Persisted by AdminActionLogger; read by the admin backend.
 */
#[ORM\Entity(repositoryClass: AdminActionLogRepository::class)]
#[ORM\Table(name: 'admin_action_log')]
class AdminActionLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $actor;

    #[ORM\Column(type: 'string', length: 40)]
    private string $action;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $targetUser;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(?User $actor, string $action, ?User $targetUser, ?string $note = null)
    {
        $this->actor = $actor;
        $this->action = $action;
        $this->targetUser = $targetUser;
        $this->note = $note;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getTargetUser(): ?User
    {
        return $this->targetUser;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
