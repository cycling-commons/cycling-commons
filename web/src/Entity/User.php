<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Entity;

use App\Account\DateFormat;
use App\Account\DistanceUnit;
use App\Account\ElevationUnit;
use App\Account\RowsPerPage;
use App\Account\TimeFormat;
use App\Catalog\BikeType;
use App\Catalog\MapTheme;
use App\Catalog\MapViewMode;
use App\Catalog\RidingStyle;
use App\Form\CatalogFieldConstraints;
use App\Repository\UserRepository;
use App\Validator\PlainDisplayName;
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
 * Registered account: roles, 2FA, lockout, self-service deletion.
 *
 * @see docs/specs/account-and-auth.md §1
 *
 * @api
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\UniqueConstraint(name: 'uniq_users_email', columns: ['email'])]
#[ORM\UniqueConstraint(name: 'uniq_users_uuid', columns: ['uuid'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['email'], message: 'form.error_email_taken')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, TwoFactorInterface, BackupCodeInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid', unique: false)]
    private ?Uuid $uuid = null;

    // Entity-level so every write path is covered; length matches the column.
    #[ORM\Column(type: 'string', length: 180, unique: true)]
    #[Assert\NotBlank(message: 'form.error_email_required')]
    #[Assert\Email(message: 'form.error_email_invalid')]
    #[Assert\Length(max: 180, maxMessage: 'form.error_email_long')]
    private string $email = '';

    #[ORM\Column(type: 'string')]
    private string $password = '';

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $roles = [];

    // Format on the entity; NotBlank + min length live on forms (empty is supported).
    // LengthValidator skips null only, not ''.
    #[Assert\Length(
        max: 100,
        maxMessage: 'form.error_display_name_long',
    )]
    #[Assert\NoSuspiciousCharacters(
        locales: CatalogFieldConstraints::LOCALES,
        restrictionLevelMessage: 'contribute.error.suspicious_characters',
        invisibleMessage: 'contribute.error.suspicious_characters',
        mixedNumbersMessage: 'contribute.error.suspicious_characters',
        hiddenOverlayMessage: 'contribute.error.suspicious_characters',
    )]
    // CHECK_INVISIBLE misses a lone Cf (U+200B); this regex catches it.
    #[Assert\Regex(pattern: '/\p{Cf}/u', match: false, message: 'contribute.error.invisible_characters')]
    // Not unique (docs/specs/account-and-auth.md §9); $uuid is public identity.
    #[PlainDisplayName]
    #[ORM\Column(type: 'string', length: 100)]
    private string $displayName = '';

    // Rider preferences (docs/specs/account-and-auth.md §9); accessors drop unknown enum values.
    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $bikeTypes = [];

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $ridingStyles = [];

    #[ORM\ManyToOne(targetEntity: Country::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Country $country = null;

    public const int BASE_RADIUS_DEFAULT = 40;
    public const int BASE_RADIUS_MIN = 10;
    public const int BASE_RADIUS_MAX = 150;
    public const int BASE_COORD_DECIMALS = 2;

    /** Coarse GeoJSON Point; rounded to 2 dp at write (docs/specs/map-and-search.md §4.5). */
    #[ORM\Column(type: 'geometry', nullable: true)]
    private ?string $basePoint = null;

    /** Town-level label for the scope line; never public. */
    #[ORM\Column(type: 'string', length: 120, nullable: true)]
    private ?string $basePlace = null;

    #[ORM\Column(type: 'smallint')]
    private int $baseRadiusKm = self::BASE_RADIUS_DEFAULT;

    /** @var array<mixed> json hydrates bypassing the setter; getBaseRegionIds() guards */
    #[ORM\Column(type: 'json')]
    private array $baseRegionIds = [];

    /** @var array<mixed> same hydration caveat as $baseRegionIds */
    #[ORM\Column(type: 'json')]
    private array $baseCountryCodes = [];

    // Null = follow switcher / browser / site default.
    #[ORM\Column(type: 'string', length: 5, nullable: true)]
    private ?string $locale = null;

    #[ORM\Column(type: 'string', length: 16, options: ['default' => 'auto'])]
    private string $defaultMapMode = MapViewMode::Auto->value;

    #[ORM\Column(name: 'map_theme', type: 'string', length: 16, options: ['default' => 'dark'])]
    private string $mapTheme = MapTheme::Dark->value;

    #[ORM\Column(name: 'date_format', type: 'string', length: 10, options: ['default' => 'auto'])]
    private string $dateFormat = DateFormat::Auto->value;

    #[ORM\Column(name: 'time_format', type: 'string', length: 10, options: ['default' => 'auto'])]
    private string $timeFormat = TimeFormat::Auto->value;

    #[ORM\Column(name: 'distance_unit', type: 'string', length: 8, options: ['default' => 'km'])]
    private string $distanceUnit = DistanceUnit::Km->value;

    #[ORM\Column(name: 'elevation_unit', type: 'string', length: 8, options: ['default' => 'm'])]
    private string $elevationUnit = ElevationUnit::M->value;

    #[ORM\Column(name: 'rows_per_page', type: 'string', length: 8, options: ['default' => 'auto'])]
    private string $rowsPerPage = RowsPerPage::Auto->value;

    #[ORM\Column(type: 'boolean')]
    private bool $emailVerified = false;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $emailVerifiedAt = null;

    #[ORM\Column(type: 'boolean')]
    private bool $twoFaEnabled = false;

    // Encrypted at rest (AES-256-GCM, APP_SECRET); a DB leak does not expose the seed.
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

    /**
     * When the rider declared they were 16+. Null if the gate did not apply.
     *
     * @see docs/specs/account-and-auth.md §2
     */
    #[ORM\Column(name: 'age_confirmed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $ageConfirmedAt = null;

    /** Credit on approved photos after deletion (docs/specs/photo-uploads.md §6). Default: anonymize. */
    #[ORM\Column(name: 'keep_media_credit', type: 'boolean', options: ['default' => false])]
    private bool $keepMediaCredit = false;

    #[ORM\Column(type: 'boolean')]
    private bool $publicProfile = false;

    /**
     * Consent to be told when a release ships. Off unless a rider turns it on.
     *
     * The flag is the current answer; the proof that it was given, and to what
     * wording, is a `consent_record` row under {@see \App\Account\UpdatesConsent}.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $updatesOptIn = false;

    /**
     * The dormancy clock. Written on every successful sign-in.
     *
     * Backfilled to `createdAt` by the migration rather than left null, because
     * null would read as "never signed in" for accounts that simply predate the
     * column. @see \App\Account\DormancyLadder
     */
    #[ORM\Column(name: 'last_login_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    /**
     * When each dormancy warning went out. Three columns, not one stage, so
     * deletion can ask "were they told three times?" and get a real answer.
     */
    #[ORM\Column(name: 'inactivity_12m_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $inactivity12mAt = null;

    #[ORM\Column(name: 'inactivity_22m_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $inactivity22mAt = null;

    #[ORM\Column(name: 'inactivity_23m_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $inactivity23mAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $updatedAt = null;

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
    }

    #[\Override]
    public function getPassword(): string
    {
        return $this->password;
    }

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
     * Peppered HMAC of a backup code (APP_SECRET). Rotating the secret invalidates stored codes.
     *
     * @api
     */
    public static function hashBackupCode(string $code): string
    {
        $secret = $_SERVER['APP_SECRET'] ?? $_ENV['APP_SECRET'] ?? getenv('APP_SECRET');
        if (!\is_string($secret) || '' === $secret) {
            throw new \LogicException('APP_SECRET must be set to hash backup codes.');
        }

        return hash_hmac('sha256', $code, hash_hkdf('sha256', $secret, 32, 'cc-backup-code-v1'));
    }

    public function isLocked(): bool
    {
        return null !== $this->lockedUntil && $this->lockedUntil > new \DateTimeImmutable();
    }

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

    /** @param array<string> $ccs */
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

    public function getMapTheme(): MapTheme
    {
        return MapTheme::tryFrom($this->mapTheme) ?? MapTheme::Dark;
    }

    public function setMapTheme(MapTheme $theme): static
    {
        $this->mapTheme = $theme->value;

        return $this;
    }

    public function getDateFormat(): DateFormat
    {
        return DateFormat::tryFrom($this->dateFormat) ?? DateFormat::Auto;
    }

    public function setDateFormat(DateFormat $format): static
    {
        $this->dateFormat = $format->value;

        return $this;
    }

    public function getTimeFormat(): TimeFormat
    {
        return TimeFormat::tryFrom($this->timeFormat) ?? TimeFormat::Auto;
    }

    public function setTimeFormat(TimeFormat $format): static
    {
        $this->timeFormat = $format->value;

        return $this;
    }

    public function getDistanceUnit(): DistanceUnit
    {
        return DistanceUnit::tryFrom($this->distanceUnit) ?? DistanceUnit::Km;
    }

    public function setDistanceUnit(DistanceUnit $unit): static
    {
        $this->distanceUnit = $unit->value;

        return $this;
    }

    public function getElevationUnit(): ElevationUnit
    {
        return ElevationUnit::tryFrom($this->elevationUnit) ?? ElevationUnit::M;
    }

    public function setElevationUnit(ElevationUnit $unit): static
    {
        $this->elevationUnit = $unit->value;

        return $this;
    }

    public function getRowsPerPage(): RowsPerPage
    {
        return RowsPerPage::tryFrom($this->rowsPerPage) ?? RowsPerPage::Auto;
    }

    public function setRowsPerPage(RowsPerPage $rows): static
    {
        $this->rowsPerPage = $rows->value;

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

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    /**
     * Coming back clears the whole ladder.
     *
     * Both fields together, always: leaving a stale notice behind would mean a
     * rider who returned after the final warning never gets warned again.
     */
    public function recordLogin(\DateTimeImmutable $at): static
    {
        $this->lastLoginAt = $at;
        $this->inactivity12mAt = null;
        $this->inactivity22mAt = null;
        $this->inactivity23mAt = null;

        return $this;
    }

    /**
     * Which warnings have gone out, keyed by {@see \App\Account\DormancyLadder}
     * notice code.
     *
     * @return array<string, bool>
     */
    public function dormancyNoticesSent(): array
    {
        return [
            'm12' => null !== $this->inactivity12mAt,
            'm22' => null !== $this->inactivity22mAt,
            'm23_final' => null !== $this->inactivity23mAt,
        ];
    }

    public function recordDormancyNotice(string $code, \DateTimeImmutable $at): static
    {
        match ($code) {
            'm12' => $this->inactivity12mAt = $at,
            'm22' => $this->inactivity22mAt = $at,
            'm23_final' => $this->inactivity23mAt = $at,
            default => throw new \InvalidArgumentException(sprintf('Unknown dormancy notice "%s".', $code)),
        };

        return $this;
    }

    public function isUpdatesOptIn(): bool
    {
        return $this->updatesOptIn;
    }

    public function setUpdatesOptIn(bool $updatesOptIn): static
    {
        $this->updatesOptIn = $updatesOptIn;

        return $this;
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

    public function getAgeConfirmedAt(): ?\DateTimeImmutable
    {
        return $this->ageConfirmedAt;
    }

    public function confirmAge(\DateTimeImmutable $at): static
    {
        $this->ageConfirmedAt = $at;

        return $this;
    }

    public function isKeepMediaCredit(): bool
    {
        return $this->keepMediaCredit;
    }

    public function setKeepMediaCredit(bool $keep): static
    {
        $this->keepMediaCredit = $keep;

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
