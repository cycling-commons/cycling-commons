<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Service;

use App\Catalog\Entity\Region;
use App\Entity\User;
use App\Messaging\MessageService;
use App\Messaging\UserMessageKind;
use App\Moderation\Entity\ModeratorArea;
use App\Repository\UserRepository;
use App\World\Entity\Country;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Admin mutations on a User. Every change flushes and is audited.
 *
 * @see docs/specs/account-and-auth.md §6
 *
 * @api
 */
final class UserAdminService
{
    public const string UNLOCK = 'unlock';
    public const string DISARM_2FA = 'disarm_2fa';
    public const string VERIFY_EMAIL = 'verify_email';
    public const string UNVERIFY_EMAIL = 'unverify_email';
    public const string GRANT_CURATOR = 'grant_curator';
    public const string REVOKE_CURATOR = 'revoke_curator';
    public const string GRANT_ADMIN = 'grant_admin';
    public const string REVOKE_ADMIN = 'revoke_admin';
    public const string REMOVE_ACCOUNT = 'remove_account';
    public const string CANCEL_REMOVAL = 'cancel_removal';
    public const string MODERATOR_AREAS = 'moderator_areas';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly AdminActionLogger $logger,
        private readonly UserDeletionService $deletion,
        private readonly MessageService $messages,
    ) {
    }

    public function hasRole(User $u, string $role): bool
    {
        return \in_array($role, $u->getRoles(), true);
    }

    public function unlock(User $target, User $actor): void
    {
        $target->setFailedLoginAttempts(0);
        $target->setLockedUntil(null);
        $this->commit($actor, self::UNLOCK, $target);
    }

    public function disarmTwoFa(User $target, User $actor): void
    {
        $target->setTwoFaEnabled(false);
        $target->setTotpSecret(null);
        $target->setBackupCodes([]);
        $this->commit($actor, self::DISARM_2FA, $target);
    }

    public function verifyEmail(User $target, User $actor): void
    {
        $target->setEmailVerified(true);
        $target->setEmailVerifiedAt(new \DateTimeImmutable());
        $this->commit($actor, self::VERIFY_EMAIL, $target);
    }

    public function unverifyEmail(User $target, User $actor): void
    {
        $target->setEmailVerified(false);
        $target->setEmailVerifiedAt(null);
        $this->commit($actor, self::UNVERIFY_EMAIL, $target);
    }

    public function grantCurator(User $target, User $actor): void
    {
        $this->setRole($target, 'ROLE_CURATOR', true);
        $this->commit($actor, self::GRANT_CURATOR, $target);
    }

    public function revokeCurator(User $target, User $actor): void
    {
        $this->setRole($target, 'ROLE_CURATOR', false);
        $this->commit($actor, self::REVOKE_CURATOR, $target);
    }

    public function grantAdmin(User $target, User $actor): void
    {
        $this->setRole($target, 'ROLE_ADMIN', true);
        $this->commit($actor, self::GRANT_ADMIN, $target);
    }

    public function revokeAdmin(User $target, User $actor): void
    {
        $this->assertNotSelf($target, $actor);
        $this->assertNotLastAdmin($target);
        $this->setRole($target, 'ROLE_ADMIN', false);
        $this->commit($actor, self::REVOKE_ADMIN, $target);
    }

    public function removeAccount(User $target, User $actor): void
    {
        $this->assertNotSelf($target, $actor);
        $this->assertNotLastAdmin($target);

        $email = $target->getEmail();
        $this->em->wrapInTransaction(function () use ($actor, $target, $email): void {
            // Personal data goes; contributions are anonymised (docs/specs/account-and-auth.md §6.3).
            $this->logger->log($actor, self::REMOVE_ACCOUNT, $target, 'Removed account: '.$email);
            $this->deletion->purge($target);
            $this->em->flush();
        });
    }

    public function cancelPendingRemoval(User $target, User $actor): void
    {
        $target->setDeletionRequestedAt(null);
        $target->setDeletionCode(null);
        $this->commit($actor, self::CANCEL_REMOVAL, $target);
    }

    /**
     * Replace moderation-area rows and audit the set.
     *
     * @see docs/specs/moderation-and-contribution.md §9.4
     *
     * @param list<string> $countryCodes
     * @param list<int>    $regionIds
     */
    public function setModeratorAreas(User $target, User $actor, array $countryCodes, array $regionIds): void
    {
        $countryCodes = array_values(array_unique(array_map(strtoupper(...), $countryCodes)));
        $regionIds = array_values(array_unique(array_map(intval(...), $regionIds)));

        $regions = [] !== $regionIds ? $this->em->getRepository(Region::class)->findBy(['id' => $regionIds]) : [];
        if (\count($regions) !== \count($regionIds)) {
            throw new \InvalidArgumentException('Unknown region in assignment.');
        }
        $countries = [] !== $countryCodes ? $this->em->getRepository(Country::class)->findBy(['iso2' => $countryCodes]) : [];
        if (\count($countries) !== \count($countryCodes)) {
            throw new \InvalidArgumentException('Unknown country in assignment.');
        }

        $note = sprintf(
            'regions: %s; countries: %s',
            [] !== $regions ? implode(', ', array_map(static fn (Region $r): string => $r->getSlug(), $regions)) : 'none',
            [] !== $countryCodes ? implode(', ', $countryCodes) : 'none',
        );

        $before = $this->areaKeys((int) $target->getId());

        $this->em->wrapInTransaction(function () use ($target, $actor, $countryCodes, $regionIds, $note): void {
            $this->em->createQuery('DELETE FROM App\Moderation\Entity\ModeratorArea m WHERE m.userId = :uid')
                ->setParameter('uid', (int) $target->getId())
                ->execute();
            foreach ($regionIds as $rid) {
                $this->em->persist(new ModeratorArea((int) $target->getId(), $rid, null));
            }
            foreach ($countryCodes as $cc) {
                $this->em->persist(new ModeratorArea((int) $target->getId(), null, $cc));
            }
            $this->commit($actor, self::MODERATOR_AREAS, $target, $note);
        });

        $after = $this->areaKeys((int) $target->getId());
        if ($before !== $after) {
            $this->messages->sendSystem(
                (int) $target->getId(),
                UserMessageKind::ModeratorAreasChanged,
                'areas',
                (int) $target->getId(),
                '',
                'messages.body.areas_changed',
                ['%areas%' => $this->areaNames($regionIds, $countryCodes)],
            );
            $this->em->flush();
        }
    }

    /**
     * Sorted fingerprint of coverage. Empty means global.
     *
     * @return list<string>
     */
    private function areaKeys(int $userId): array
    {
        $keys = [];
        foreach ($this->em->getRepository(ModeratorArea::class)->findBy(['userId' => $userId]) as $area) {
            $keys[] = null !== $area->getRegionId() ? 'r:'.$area->getRegionId() : 'c:'.$area->getCountryCode();
        }
        sort($keys);

        return $keys;
    }

    /**
     * Area names for the notification. Empty assignment is global.
     *
     * @param list<int>    $regionIds
     * @param list<string> $countryCodes
     */
    private function areaNames(array $regionIds, array $countryCodes): string
    {
        if ([] === $regionIds && [] === $countryCodes) {
            // English only: translator would lock in the admin's locale (docs/specs/moderation-and-contribution.md §7).
            return 'everywhere';
        }

        $names = [];
        foreach ($this->em->getRepository(Region::class)->findBy(['id' => $regionIds]) as $region) {
            $names[] = $region->getName();
        }
        foreach ($this->em->getRepository(Country::class)->findBy(['iso2' => $countryCodes]) as $country) {
            $names[] = $country->getName();
        }
        sort($names);

        return implode(', ', $names);
    }

    private function assertNotSelf(User $target, User $actor): void
    {
        if ($target->getId() === $actor->getId()) {
            throw new GuardrailViolationException('You cannot perform this action on your own account.');
        }
    }

    private function assertNotLastAdmin(User $target): void
    {
        if ($this->hasRole($target, 'ROLE_ADMIN') && $this->users->countWithRole('ROLE_ADMIN') <= 1) {
            throw new GuardrailViolationException('Cannot remove the last remaining administrator.');
        }
    }

    /** Add or remove an elevated role, never storing the implicit ROLE_USER. */
    private function setRole(User $user, string $role, bool $enabled): void
    {
        // Drop ROLE_USER (implicit) and the target role in one pass, then append
        // it back only if enabling, so there is no residual duplicate to remove.
        $roles = array_values(array_diff($user->getRoles(), ['ROLE_USER', $role]));
        if ($enabled) {
            $roles[] = $role;
        }
        $user->setRoles($roles);
    }

    private function commit(User $actor, string $action, User $target, ?string $note = null): void
    {
        // The mutation and its audit row are one transaction so they can never
        // diverge: both commit or both roll back.
        $this->em->wrapInTransaction(function () use ($actor, $action, $target, $note): void {
            $this->em->flush();
            $this->logger->log($actor, $action, $target, $note);
        });
    }
}
