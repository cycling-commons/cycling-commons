<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Service;

use App\Catalog\Entity\Region;
use App\Entity\User;
use App\Moderation\Entity\ModeratorArea;
use App\Repository\UserRepository;
use App\World\Entity\Country;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Administrative support operations on a User account. All mutations flush and
 * write an audit row (AdminActionLogger). Guardrails prevent an admin from
 * locking themselves out or removing the last administrator.
 *
 * @api Autowired by the DI container; consumed by UserCrudController.
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
        // Audit + deletion are one transaction: a failure in either (e.g. a
        // deletion hook) rolls BOTH back, so the audit trail can never claim a
        // removal that did not happen (#15).
        $this->em->wrapInTransaction(function () use ($actor, $target, $email): void {
            // Log first (target still exists); the target FK becomes NULL when the
            // row is deleted (ON DELETE SET NULL), so snapshot the email into the note.
            // Commons rule: personal data goes; contributed data is anonymised,
            // never cascade-deleted — see docs/specs/account-and-auth.md §6.3.
            $this->logger->log($actor, self::REMOVE_ACCOUNT, $target, 'Removed account: '.$email);
            // Route through the shared deletion seam so admin removal runs the
            // same UserDeletionHookInterface anonymisation as self-service (#41).
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
     * Replace the target's moderation-area rows (moderator-areas spec
     * 2026-07-14) and audit the resulting set. Codes/ids are validated
     * against world_country/region — unknown values are an
     * \InvalidArgumentException (surfaced as the desk's danger flash).
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

        // Rows + audit are one transaction (#15 precedent): commit() below opens
        // its own wrapInTransaction, but Doctrine's connection nests transactions
        // by ref-count rather than starting a second one, so this stays atomic
        // with the DELETE/persist calls that precede it.
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
    }

    // ── Guardrails ────────────────────────────────────────────────────────────

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

    // ── Internals ───────────────────────────────────────────────────────────────

    /** Add or remove an elevated role, never storing the implicit ROLE_USER. */
    private function setRole(User $user, string $role, bool $enabled): void
    {
        // Drop ROLE_USER (implicit) and the target role in one pass, then append
        // it back iff enabling — no residual duplicate to unique away.
        $roles = array_values(array_diff($user->getRoles(), ['ROLE_USER', $role]));
        if ($enabled) {
            $roles[] = $role;
        }
        $user->setRoles($roles);
    }

    private function commit(User $actor, string $action, User $target, ?string $note = null): void
    {
        // The mutation and its audit row are one transaction so they can never
        // diverge (#15) — both commit or both roll back.
        $this->em->wrapInTransaction(function () use ($actor, $action, $target, $note): void {
            $this->em->flush();
            $this->logger->log($actor, $action, $target, $note);
        });
    }
}
