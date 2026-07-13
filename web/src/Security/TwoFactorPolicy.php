<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Single source of truth for the "who must enrol in 2FA" policy (spec §7.3):
 * mandatory for ROLE_CURATOR and above, optional for plain users.
 *
 * Both {@see LoginSuccessHandler} (login-time redirect) and
 * {@see TwoFactorSetupEnforcer} (per-request gate) consult this, so the rule is
 * defined once and is role-hierarchy aware (ROLE_ADMIN implies ROLE_CURATOR) —
 * no more divergent hand-rolled `in_array('ROLE_CURATOR') || in_array('ROLE_ADMIN')`
 * checks (review #39).
 *
 * @api Autowired; consumed by the login success handler and the request enforcer.
 */
final class TwoFactorPolicy
{
    public function __construct(private readonly RoleHierarchyInterface $roleHierarchy)
    {
    }

    /** 2FA is mandatory for this user's role set (curator or above). */
    public function isMandatoryFor(User $user): bool
    {
        return \in_array('ROLE_CURATOR', $this->roleHierarchy->getReachableRoleNames($user->getRoles()), true);
    }

    /** The user must still complete 2FA setup: mandatory for them, not yet active. */
    public function requiresSetup(User $user): bool
    {
        return $this->isMandatoryFor($user) && !$user->isTotpAuthenticationEnabled();
    }
}
