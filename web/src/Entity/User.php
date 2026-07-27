<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Entity;

use App\Catalog\BikeType;
use App\Catalog\MapViewMode;
use App\Catalog\RidingStyle;
use App\Repository\UserRepository;
use App\World\Entity\Country;
use Doctrine\ORM\Mapping as ORM;
use Scheb\TwoFactorBundle\Model\BackupCodeInterface;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfigurationInterface;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A registered account: one entity for every role (member, curator, admin),
 * with 2FA, lockout, and self-service deletion built in.
 *
 * @see docs/specs/account-and-auth.md §1
 *
 * @api Consumed by Symfony Security, Doctrine lifecycle events, and future
 *      auth controllers. All public methods are live entry points; Psalm
 *      must not flag them as unused.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\UniqueConstraint(name: 'uniq_users_email', columns: ['email'])]
#[ORM\UniqueConstraint(name: 'uniq_users_display_name_canonical', columns: ['display_name_canonical'])]
#[ORM\UniqueConstraint(name: 'uniq_users_uuid', columns: ['uuid'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['email'], message: 'This email address is already registered.')]
#[UniqueEntity(fields: ['displayNameCanonical'], errorPath: 'displayName', message: 'form.error_display_name_taken')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, TwoFactorInterface, BackupCodeInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid', unique: false)]
    private ?Uuid $uuid = null;

    // Validate on the entity so EVERY write path is covered (registration form,
    // future JSON API, console commands), not just the one form. Length is
    // capped at the column width so an over-long value fails validation instead
    // of blowing up at flush; Email keeps a malformed address out before
    // Address() would throw RfcComplianceException on send.
    #[ORM\Column(type: 'string', length: 180, unique: true)]
    #[Assert\NotBlank(message: 'Please enter an email address.')]
    #[Assert\Email(message: 'Please enter a valid email address.')]
    #[Assert\Length(max: 180, maxMessage: 'Email address may not exceed {{ limit }} characters.')]
    private string $email = '';

    #[ORM\Column(type: 'string')]
    private string $password = '';

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $roles = [];

    #[ORM\Column(type: 'string', length: 100)]
    private string $displayName = '';

    // Lowercased+trimmed shadow copy of displayName, maintained by
    // setDisplayName() so EVERY write path (registration, settings, console,
    // admin CRUD, fixtures) keeps it in sync. A plain unique constraint on
    // this column gives case-insensitive display-name uniqueness without a
    // functional index Doctrine can't model. An empty name canonicalizes to
    // NULL so unnamed rows (tests, partial flows) never collide; NULL is
    // ignored by both the Postgres unique index and UniqueEntity (ignoreNull).
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $displayNameCanonical = null;

    // Rider preferences (docs/specs/account-and-auth.md §9): which bikes
    // they ride and what kind of riding they do. Stored as enum value
    // strings; read via the enum-typed accessors, which drop unknown values
    // so a vocabulary change can never fatal a render. The map will later
    // prefilter on these.
    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $bikeTypes = [];

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $ridingStyles = [];

    // Optional home country (World bundle reference data).
    #[ORM\ManyToOne(targetEntity: Country::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Country $country = null;

    public const int BASE_RADIUS_DEFAULT = 40;
    public const int BASE_RADIUS_MIN = 10;
    public const int BASE_RADIUS_MAX = 150;
    public const int BASE_COORD_DECIMALS = 2;

    /**
     * Optional rider base location, COARSE ONLY: coordinates are rounded to
     * 2 decimals (~1 km) at write time; the raw pick is never persisted
     * (region-scoping-design.md §4 privacy invariants). GeoJSON Point.
     */
    #[ORM\Column(type: 'geometry', nullable: true)]
    private ?string $basePoint = null;

    /** Town-level label for the scope line ("Near Namur · 40 km"); never public. */
    #[ORM\Column(type: 'string', length: 120, nullable: true)]
    private ?string $basePlace = null;

    #[ORM\Column(type: 'smallint')]
    private int $baseRadiusKm = self::BASE_RADIUS_DEFAULT;

    // Doctrine's json type hydrates this property directly (bypassing
    // setBaseRegionIds()), so a stale/malformed DB row can hand back anything
    // JSON allows — hence `array`, not `list<int>`, and the is_numeric() guard
    // in getBaseRegionIds() below actually does work.
    /** @var array<mixed> derived region ids (ST_DWithin, cap 8) — recomputed on save + region import */
    #[ORM\Column(type: 'json')]
    private array $baseRegionIds = [];

    // Same hydration caveat as $baseRegionIds above.
    /** @var array<mixed> derived ISO 3166-1 alpha-2 codes of those regions */
    #[ORM\Column(type: 'json')]
    private array $baseCountryCodes = [];

    // Preferred UI language (short code: en|fr|nl|de). Null = follow the
    // language switcher / browser / site default.
    #[ORM\Column(type: 'string', length: 5, nullable: true)]
    private ?string $locale = null;

    // Which view mode the map opens in
    // (2026-07-27-map-view-mode-default-design.md §2). 'auto' = let the active
    // region decide; the other values are the rider overriding that on every
    // device. Stored as the enum's value string and read through the
    // enum-typed accessor, which falls back to Auto on an unknown value so a
    // vocabulary change can never fatal the map render.
    #[ORM\Column(type: 'string', length: 16, options: ['default' => 'auto'])]
    private string $defaultMapMode = MapViewMode::Auto->value;

    #[ORM\Column(type: 'boolean')]
    private bool $emailVerified = false;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $emailVerifiedAt = null;

    #[ORM\Column(type: 'boolean')]
    private bool $twoFaEnabled = false;

    // Encrypted at rest (AES-256-GCM, key from APP_SECRET); a DB leak alone
    // does not expose the authenticator seed.
    #[ORM\Column(type: 'encrypted_string', nullable: true)]
    private ?string $totpSecret = null;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $backupCodes = [];

    #[ORM\Column(type: 'integer')]
    private int $failedLoginAttempts = 0;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lockedUntil = null;

    #[ORM\Column(type: 'string', length: 16, nullable: true)]
    private ?string $deletionCode = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletionRequestedAt = null;

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
        return in_array(self::hashBackupCode($code), $this->backupCodes, true);
    }

    #[\Override]
    public function invalidateBackupCode(string $code): void
    {
        $i = array_search(self::hashBackupCode($code), $this->backupCodes, true);
        if (false !== $i) {
            unset($this->backupCodes[$i]);
            $this->backupCodes = array_values($this->backupCodes);
        }
    }

    /**
     * Keyed (peppered) hash for a single-use backup code.
     *
     * The codes carry ≥80 bits of entropy (see TwoFactorController), so a fast
     * digest is not itself the risk, but keying it with a secret derived from
     * APP_SECRET (never stored in the DB) means a database-only leak cannot even
     * compute candidate hashes, closing the offline-enumeration path that an
     * unsalted SHA-256 left open. Rotating APP_SECRET invalidates
     * stored codes (same trade-off as the encrypted TOTP secret).
     *
     * @api Also used by the enrolment controller when first storing codes.
     */
    public static function hashBackupCode(string $code): string
    {
        $secret = $_SERVER['APP_SECRET'] ?? $_ENV['APP_SECRET'] ?? getenv('APP_SECRET');
        if (!\is_string($secret) || '' === $secret) {
            throw new \LogicException('APP_SECRET must be set to hash backup codes.');
        }

        return hash_hmac('sha256', $code, hash_hkdf('sha256', $secret, 32, 'cc-backup-code-v1'));
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
        $canonical = mb_strtolower(trim($displayName));
        $this->displayNameCanonical = '' === $canonical ? null : $canonical;

        return $this;
    }

    public function getDisplayNameCanonical(): ?string
    {
        return $this->displayNameCanonical;
    }

    /** @return list<BikeType> */
    public function getBikeTypes(): array
    {
        return array_values(array_filter(
            array_map(
                static fn (string $v): ?BikeType => BikeType::tryFrom($v),
                $this->bikeTypes,
            ),
            static fn (?BikeType $t): bool => null !== $t,
        ));
    }

    /** @param list<BikeType> $bikeTypes */
    public function setBikeTypes(array $bikeTypes): static
    {
        $this->bikeTypes = array_values(array_unique(array_map(
            static fn (BikeType $t): string => $t->value,
            $bikeTypes,
        )));

        return $this;
    }

    /** @return list<RidingStyle> */
    public function getRidingStyles(): array
    {
        return array_values(array_filter(
            array_map(
                static fn (string $v): ?RidingStyle => RidingStyle::tryFrom($v),
                $this->ridingStyles,
            ),
            static fn (?RidingStyle $s): bool => null !== $s,
        ));
    }

    /** @param list<RidingStyle> $ridingStyles */
    public function setRidingStyles(array $ridingStyles): static
    {
        $this->ridingStyles = array_values(array_unique(array_map(
            static fn (RidingStyle $s): string => $s->value,
            $ridingStyles,
        )));

        return $this;
    }

    public function getCountry(): ?Country
    {
        return $this->country;
    }

    public function setCountry(?Country $country): static
    {
        $this->country = $country;

        return $this;
    }

    public function setBaseLocation(float $lat, float $lng, ?string $place): static
    {
        $lat = round($lat, self::BASE_COORD_DECIMALS);
        $lng = round($lng, self::BASE_COORD_DECIMALS);
        $this->basePoint = json_encode(
            ['type' => 'Point', 'coordinates' => [$lng, $lat]],
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
        );
        $this->basePlace = null !== $place ? mb_substr(trim($place), 0, 120) : null;
        if ('' === $this->basePlace) {
            $this->basePlace = null;
        }

        return $this;
    }

    public function clearBaseLocation(): static
    {
        $this->basePoint = null;
        $this->basePlace = null;
        $this->baseRadiusKm = self::BASE_RADIUS_DEFAULT;
        $this->baseRegionIds = [];
        $this->baseCountryCodes = [];

        return $this;
    }

    public function hasBaseLocation(): bool
    {
        return null !== $this->basePoint;
    }

    /** @return array{0: float, 1: float}|null [lat, lng] */
    private function baseCoords(): ?array
    {
        if (null === $this->basePoint) {
            return null;
        }
        try {
            $g = json_decode($this->basePoint, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        $c = $g['coordinates'] ?? null;
        if (!\is_array($c) || !\is_numeric($c[0] ?? null) || !\is_numeric($c[1] ?? null)) {
            return null;
        }

        return [(float) $c[1], (float) $c[0]];
    }

    public function getBaseLat(): ?float
    {
        return $this->baseCoords()[0] ?? null;
    }

    public function getBaseLng(): ?float
    {
        return $this->baseCoords()[1] ?? null;
    }

    public function getBasePlace(): ?string
    {
        return $this->basePlace;
    }

    public function getBaseRadiusKm(): int
    {
        return max(self::BASE_RADIUS_MIN, min(self::BASE_RADIUS_MAX, $this->baseRadiusKm));
    }

    public function setBaseRadiusKm(int $km): static
    {
        $this->baseRadiusKm = max(self::BASE_RADIUS_MIN, min(self::BASE_RADIUS_MAX, $km));

        return $this;
    }

    /** @return list<int> */
    public function getBaseRegionIds(): array
    {
        $out = [];
        foreach ($this->baseRegionIds as $id) {
            if (is_numeric($id) && (int) $id > 0 && !\in_array((int) $id, $out, true)) {
                $out[] = (int) $id;
            }
        }

        return $out;
    }

    /** @param array<int|string> $ids */
    public function setBaseRegionIds(array $ids): static
    {
        $clean = [];
        foreach ($ids as $id) {
            if (is_numeric($id) && (int) $id > 0 && !\in_array((int) $id, $clean, true)) {
                $clean[] = (int) $id;
            }
        }
        $this->baseRegionIds = $clean;

        return $this;
    }

    /** @return list<string> */
    public function getBaseCountryCodes(): array
    {
        $out = [];
        foreach ($this->baseCountryCodes as $cc) {
            if (\is_string($cc) && 1 === preg_match('/^[A-Za-z]{2}$/D', $cc) && !\in_array(strtoupper($cc), $out, true)) {
                $out[] = strtoupper($cc);
            }
        }

        return $out;
    }

    /** @param array<string> $ccs already-string per the declared param type (unlike setBaseRegionIds, no caller passes non-strings) */
    public function setBaseCountryCodes(array $ccs): static
    {
        $clean = [];
        foreach ($ccs as $cc) {
            if (1 === preg_match('/^[A-Za-z]{2}$/D', $cc) && !\in_array(strtoupper($cc), $clean, true)) {
                $clean[] = strtoupper($cc);
            }
        }
        $this->baseCountryCodes = $clean;

        return $this;
    }

    public function getLocale(): ?string
    {
        return $this->locale;
    }

    public function setLocale(?string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function getDefaultMapMode(): MapViewMode
    {
        return MapViewMode::tryFrom($this->defaultMapMode) ?? MapViewMode::Auto;
    }

    public function setDefaultMapMode(MapViewMode $mode): static
    {
        $this->defaultMapMode = $mode->value;

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

    public function getDeletionCode(): ?string
    {
        return $this->deletionCode;
    }

    public function setDeletionCode(?string $deletionCode): static
    {
        $this->deletionCode = $deletionCode;

        return $this;
    }

    public function getDeletionRequestedAt(): ?\DateTimeImmutable
    {
        return $this->deletionRequestedAt;
    }

    public function setDeletionRequestedAt(?\DateTimeImmutable $deletionRequestedAt): static
    {
        $this->deletionRequestedAt = $deletionRequestedAt;

        return $this;
    }
}
