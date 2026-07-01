<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
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

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly AdminActionLogger $logger,
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

        // Log first (target still exists); the target FK becomes NULL when the
        // row is deleted (ON DELETE SET NULL), so snapshot the email into the note.
        // Commons rule: personal data goes; contributed data (none yet) is anonymised,
        // never cascade-deleted — see docs/specs/2026-07-01-admin-panel-account-support-design.md §7.
        $this->logger->log($actor, self::REMOVE_ACCOUNT, $target, 'Removed account: '.$target->getEmail());

        $this->em->remove($target);
        $this->em->flush();
    }

    public function cancelPendingRemoval(User $target, User $actor): void
    {
        $target->setDeletionRequestedAt(null);
        $target->setDeletionCode(null);
        $this->commit($actor, self::CANCEL_REMOVAL, $target);
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
        $roles = array_values(array_filter(
            $user->getRoles(),
            static fn (string $r): bool => 'ROLE_USER' !== $r,
        ));
        $roles = array_values(array_filter($roles, static fn (string $r): bool => $r !== $role));
        if ($enabled) {
            $roles[] = $role;
        }
        $user->setRoles(array_values(array_unique($roles)));
    }

    private function commit(User $actor, string $action, User $target, ?string $note = null): void
    {
        $this->em->flush();
        $this->logger->log($actor, $action, $target, $note);
    }
}
