<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Scheb\TwoFactorBundle\Model\BackupCodeInterface;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfigurationInterface;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @api Consumed by Symfony Security, Doctrine lifecycle events, and future auth controllers.
 *      All public methods are live entry points; Psalm must not flag them as unused.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\UniqueConstraint(name: 'uniq_users_email', columns: ['email'])]
#[ORM\HasLifecycleCallbacks]
class User implements UserInterface, PasswordAuthenticatedUserInterface, TwoFactorInterface, BackupCodeInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid', unique: false)]
    private ?Uuid $uuid = null;

    #[ORM\Column(type: 'string', length: 180, unique: true)]
    private string $email = '';

    #[ORM\Column(type: 'string')]
    private string $password = '';

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $roles = [];

    #[ORM\Column(type: 'string', length: 100)]
    private string $displayName = '';

    #[ORM\Column(type: 'boolean')]
    private bool $emailVerified = false;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $emailVerifiedAt = null;

    #[ORM\Column(type: 'boolean')]
    private bool $twoFaEnabled = false;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $totpSecret = null;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $backupCodes = [];

    #[ORM\Column(type: 'integer')]
    private int $failedLoginAttempts = 0;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lockedUntil = null;

    #[ORM\Column(type: 'boolean')]
    private bool $publicProfile = false;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $updatedAt = null;

    // ── Lifecycle callbacks ──────────────────────────────────────────────────

    #[ORM\PrePersist]
    public function onCreate(): void
    {
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
        $this->uuid ??= Uuid::v7();
    }

    #[ORM\PreUpdate]
    public function onUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    // ── UserInterface ────────────────────────────────────────────────────────

    #[\Override]
    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /** @return list<string> */
    #[\Override]
    public function getRoles(): array
    {
        $r = $this->roles;
        $r[] = 'ROLE_USER';

        return array_unique($r);
    }

    #[\Override]
    public function eraseCredentials(): void
    {
        // No plain-text credential to clear
    }

    // ── PasswordAuthenticatedUserInterface ───────────────────────────────────

    #[\Override]
    public function getPassword(): string
    {
        return $this->password;
    }

    // ── TwoFactorInterface (TOTP) ────────────────────────────────────────────

    #[\Override]
    public function isTotpAuthenticationEnabled(): bool
    {
        return $this->twoFaEnabled && null !== $this->totpSecret;
    }

    #[\Override]
    public function getTotpAuthenticationUsername(): ?string
    {
        return $this->email;
    }

    #[\Override]
    public function getTotpAuthenticationConfiguration(): ?TotpConfigurationInterface
    {
        return null !== $this->totpSecret
            ? new TotpConfiguration($this->totpSecret, TotpConfiguration::ALGORITHM_SHA1, 30, 6)
            : null;
    }

    // ── BackupCodeInterface ──────────────────────────────────────────────────

    #[\Override]
    public function isBackupCode(string $code): bool
    {
        return in_array($code, $this->backupCodes, true);
    }

    #[\Override]
    public function invalidateBackupCode(string $code): void
    {
        $i = array_search($code, $this->backupCodes, true);
        if (false !== $i) {
            unset($this->backupCodes[$i]);
            $this->backupCodes = array_values($this->backupCodes);
        }
    }

    // ── Lockout helper ───────────────────────────────────────────────────────

    public function isLocked(): bool
    {
        return null !== $this->lockedUntil && $this->lockedUntil > new \DateTimeImmutable();
    }

    // ── Getters / setters ────────────────────────────────────────────────────

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): ?Uuid
    {
        return $this->uuid;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): static
    {
        $this->displayName = $displayName;

        return $this;
    }

    public function isEmailVerified(): bool
    {
        return $this->emailVerified;
    }

    public function setEmailVerified(bool $emailVerified): static
    {
        $this->emailVerified = $emailVerified;

        return $this;
    }

    public function getEmailVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->emailVerifiedAt;
    }

    public function setEmailVerifiedAt(?\DateTimeImmutable $emailVerifiedAt): static
    {
        $this->emailVerifiedAt = $emailVerifiedAt;

        return $this;
    }

    public function isTwoFaEnabled(): bool
    {
        return $this->twoFaEnabled;
    }

    public function setTwoFaEnabled(bool $twoFaEnabled): static
    {
        $this->twoFaEnabled = $twoFaEnabled;

        return $this;
    }

    public function getTotpSecret(): ?string
    {
        return $this->totpSecret;
    }

    public function setTotpSecret(?string $totpSecret): static
    {
        $this->totpSecret = $totpSecret;

        return $this;
    }

    /** @return list<string> */
    public function getBackupCodes(): array
    {
        return $this->backupCodes;
    }

    /** @param list<string> $backupCodes */
    public function setBackupCodes(array $backupCodes): static
    {
        $this->backupCodes = $backupCodes;

        return $this;
    }

    public function getFailedLoginAttempts(): int
    {
        return $this->failedLoginAttempts;
    }

    public function setFailedLoginAttempts(int $failedLoginAttempts): static
    {
        $this->failedLoginAttempts = $failedLoginAttempts;

        return $this;
    }

    public function getLockedUntil(): ?\DateTimeImmutable
    {
        return $this->lockedUntil;
    }

    public function setLockedUntil(?\DateTimeImmutable $lockedUntil): static
    {
        $this->lockedUntil = $lockedUntil;

        return $this;
    }

    public function isPublicProfile(): bool
    {
        return $this->publicProfile;
    }

    public function setPublicProfile(bool $publicProfile): static
    {
        $this->publicProfile = $publicProfile;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
