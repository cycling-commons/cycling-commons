<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * 2FA is mandatory for ROLE_CURATOR and above.
 *
 * @see docs/specs/account-and-auth.md §4
 *
 * @api
 */
final class TwoFactorPolicy
{
    /**
     * @param ?TotpAuthenticatorInterface $totp null when TOTP is off for this environment
     */
    public function __construct(
        private readonly RoleHierarchyInterface $roleHierarchy,
        private readonly ?TotpAuthenticatorInterface $totp = null,
    ) {
    }

    public function isMandatoryFor(User $user): bool
    {
        return \in_array('ROLE_CURATOR', $this->roleHierarchy->getReachableRoleNames($user->getRoles()), true);
    }

    public function requiresSetup(User $user): bool
    {
        return null !== $this->totp
            && $this->isMandatoryFor($user)
            && !$user->isTotpAuthenticationEnabled();
    }

    public function isAvailable(): bool
    {
        return null !== $this->totp;
    }
}
