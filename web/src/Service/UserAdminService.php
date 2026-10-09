<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Service;

use App\Catalog\Entity\Region;
use App\Entity\User;
use App\Messaging\MessageService;
use App\Messaging\UserMessageKind;
use App\Moderation\Entity\ModeratorArea;
use App\Moderation\StatementDecision;
use App\Moderation\StatementGround;
use App\Moderation\StatementOfReasons;
use App\Moderation\UnsentStatements;
use App\Repository\UserRepository;
use App\World\Entity\Country;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

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
    public const string SUSPEND = 'suspend';
    public const string LIFT_SUSPENSION = 'lift_suspension';
    public const string REMOVE_FOR_BREACH = 'remove_for_breach';

    /** The longest suspension an administrator can set, in days. */
    public const int SUSPENSION_MAX_DAYS = 365;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly AdminActionLogger $logger,
        private readonly UserDeletionService $deletion,
        private readonly MessageService $messages,
        // An account decision is explained by email: a suspended account
        // cannot sign in to read its messages, a removed one has none
        // (DSA Article 17(1)(c), docs/specs/account-and-auth.md §6.8). One
        // that does not go out is kept for an administrator to send again.
        private readonly UnsentStatements $statements,
        private readonly ClockInterface $clock,
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

    /**
     * Make a rider a curator together with the areas they moderate.
     * Empty lists mean all areas.
     *
     * @see docs/specs/moderation-and-contribution.md §9.4
     *
     * @param list<string> $countryCodes
     * @param list<int>    $regionIds
     */
    public function grantCuratorWithAreas(User $target, User $actor, array $countryCodes, array $regionIds): void
    {
        if ($this->hasRole($target, 'ROLE_CURATOR')) {
            throw new GuardrailViolationException('This account is already a curator. Change its areas with Assign moderation areas.');
        }
        // Checked before the transaction: a failed transaction closes the entity manager.
        $this->checkAreas($countryCodes, $regionIds);

        $this->em->wrapInTransaction(function () use ($target, $actor, $countryCodes, $regionIds): void {
            $this->grantCurator($target, $actor);
            $this->setModeratorAreas($target, $actor, $countryCodes, $regionIds);
        });
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

        $this->em->wrapInTransaction(function () use ($actor, $target): void {
            // Personal data goes; contributions are anonymised (docs/specs/account-and-auth.md §6.3).
            // The trail names the account by id: an erased address is not kept here either.
            $this->logger->log($actor, self::REMOVE_ACCOUNT, $target, 'Removed account #'.(int) $target->getId());
            $this->deletion->purge($target);
            $this->em->flush();
        });
    }

    /**
     * Suspend an account for a number of days, and email its holder the
     * statement of reasons (docs/specs/account-and-auth.md §6.8).
     *
     * The decision and its audit row commit together; the email goes after
     * the commit, so a rolled-back suspension tells nobody. The audit row
     * names the ground and the end, never the facts: those are on the
     * account, which the holder can download. An email the transport
     * refuses is kept whole under Unsent statements ({@see UnsentStatements}).
     *
     * @param bool $fromReports the decision followed reports about the account (Article 17(3)(b))
     *
     * @return bool whether the statement went out; false means it waits under Unsent statements
     *
     * @throws GuardrailViolationException on the administrator's own account or the last administrator
     * @throws \InvalidArgumentException   with a catalogue key: days out of range, a ground accounts do not take, no facts
     */
    public function suspend(User $target, User $actor, int $days, StatementGround $ground, string $facts, bool $fromReports = false): bool
    {
        $this->assertNotSelf($target, $actor);
        $this->assertNotLastAdmin($target);
        if ($days < 1 || $days > self::SUSPENSION_MAX_DAYS) {
            throw new \InvalidArgumentException('account_suspension.admin.error_days');
        }
        $facts = $this->accountFacts($ground, $facts);

        $now = $this->clock->now();
        $until = $now->modify(sprintf('+%d days', $days));
        $target->suspend($until, $ground, $facts, $actor->getId(), $now);
        $this->commit($actor, self::SUSPEND, $target, sprintf('until %s UTC · ground=%s', $until->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i'), $ground->value));

        return $this->statements->send(
            $target->getId(),
            $target->getEmail(),
            $target->getDisplayName(),
            $target->getLocale(),
            $target->getTimeZone() ?? $target->getDetectedTimeZone(),
            new StatementOfReasons(StatementDecision::AccountSuspended, $ground, $facts, 'ACCOUNT-'.(int) $target->getId(), fromReport: $fromReports, until: $until),
        );
    }

    /** End a running suspension now. The holder can sign in again; nothing is sent. */
    public function liftSuspension(User $target, User $actor): void
    {
        $target->liftSuspension($this->clock->now());
        $this->commit($actor, self::LIFT_SUSPENSION, $target);
    }

    /**
     * Remove an account for a breach of the terms, and tell its holder why
     * (DSA Article 17(1)(c), docs/specs/account-and-auth.md §6.8).
     *
     * The address, name and language are read before the purge, and the
     * statement goes once the removal has committed: a failed purge sends
     * nothing that claims a removal. The deletion is the ordinary one
     * ({@see removeAccount()}). After it the address and the facts exist
     * nowhere else, so an email the transport refuses is kept whole under
     * Unsent statements ({@see UnsentStatements}) until it is sent again.
     *
     * @param bool $fromReports the decision followed reports about the account (Article 17(3)(b))
     *
     * @return bool whether the statement went out; false means it waits under Unsent statements
     *
     * @throws GuardrailViolationException on the administrator's own account or the last administrator
     * @throws \InvalidArgumentException   with a catalogue key: a ground accounts do not take, no facts
     */
    public function removeAccountForBreach(User $target, User $actor, StatementGround $ground, string $facts, bool $fromReports = false): bool
    {
        $this->assertNotSelf($target, $actor);
        $this->assertNotLastAdmin($target);
        $facts = $this->accountFacts($ground, $facts);

        $address = $target->getEmail();
        $name = $target->getDisplayName();
        $locale = $target->getLocale();
        $zone = $target->getTimeZone() ?? $target->getDetectedTimeZone();
        $id = (int) $target->getId();

        $this->em->wrapInTransaction(function () use ($actor, $target, $ground, $id): void {
            $this->logger->log($actor, self::REMOVE_FOR_BREACH, $target, sprintf('Removed account #%d · ground=%s', $id, $ground->value));
            $this->deletion->purge($target);
            $this->em->flush();
        });

        return $this->statements->send(null, $address, $name, $locale, $zone, new StatementOfReasons(StatementDecision::AccountRemoved, $ground, $facts, 'ACCOUNT-'.$id, fromReport: $fromReports));
    }

    /**
     * The facts of an account decision, checked: a ground accounts take, and
     * the administrator's own words, which the holder reads.
     */
    private function accountFacts(StatementGround $ground, string $facts): string
    {
        if (!\in_array($ground, StatementGround::forAccounts(), true)) {
            throw new \InvalidArgumentException('account_suspension.admin.error_ground');
        }
        $facts = trim($facts);
        if ('' === $facts) {
            throw new \InvalidArgumentException('account_suspension.admin.error_facts');
        }
        if (mb_strlen($facts) > StatementOfReasons::FACTS_MAX) {
            throw new \InvalidArgumentException('moderate.error.note_too_long');
        }

        return $facts;
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
        [$countryCodes, $regionIds, $regions] = $this->checkAreas($countryCodes, $regionIds);

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
     * Normalised areas. Throws on an unknown region or country.
     *
     * @param list<string> $countryCodes
     * @param list<int>    $regionIds
     *
     * @return array{list<string>, list<int>, list<Region>}
     */
    private function checkAreas(array $countryCodes, array $regionIds): array
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

        return [$countryCodes, $regionIds, $regions];
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
