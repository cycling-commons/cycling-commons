<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Single source of truth for the "who must enrol in 2FA" policy: mandatory
 * for ROLE_CURATOR and above, optional for plain users.
 *
 * Both {@see LoginSuccessHandler} (login-time redirect) and
 * {@see TwoFactorSetupEnforcer} (per-request gate) consult this, so the rule
 * is defined once and is role-hierarchy aware (ROLE_ADMIN implies
 * ROLE_CURATOR).
 *
 * @see docs/specs/account-and-auth.md §4
 *
 * @api Autowired; consumed by the login success handler and the request enforcer.
 */
final class TwoFactorPolicy
{
    /**
     * @param ?TotpAuthenticatorInterface $totp null when the TOTP provider is
     *                                          switched off for the environment
     */
    public function __construct(
        private readonly RoleHierarchyInterface $roleHierarchy,
        private readonly ?TotpAuthenticatorInterface $totp = null,
    ) {
    }

    /** 2FA is mandatory for this user's role set (curator or above). */
    public function isMandatoryFor(User $user): bool
    {
        return \in_array('ROLE_CURATOR', $this->roleHierarchy->getReachableRoleNames($user->getRoles()), true);
    }

    /**
     * The user must still complete 2FA setup: mandatory for them, not yet
     * active, and actually completable.
     *
     * The last clause is the one with teeth. `when@dev` switches the TOTP
     * provider off for local convenience (config/packages/scheb_2fa.yaml), and
     * with it the `TotpAuthenticatorInterface` service. Without this check the
     * enforcer still sent every newly-elevated curator to /2fa/setup, which
     * then 500'd on the missing service - so approving somebody locked them out
     * of every page on the dev stack, which is exactly what happened to the
     * first curator approved through the new desk (owner-reported 2026-08-14).
     *
     * Nothing changes in prod or staging, where the provider is on and this is
     * always true: `when@dev` cannot leak into either.
     */
    public function requiresSetup(User $user): bool
    {
        return null !== $this->totp
            && $this->isMandatoryFor($user)
            && !$user->isTotpAuthenticationEnabled();
    }

    /** Whether a second factor can be enrolled at all in this environment. */
    public function isAvailable(): bool
    {
        return null !== $this->totp;
    }
}
