<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Security\TwoFactorPolicy;
use PHPUnit\Framework\TestCase;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchy;

/**
 * What the 2FA policy does when the TOTP provider is switched off.
 *
 * `when@dev` disables it for local convenience
 * (config/packages/scheb_2fa.yaml), which also removes the
 * `TotpAuthenticatorInterface` service. Two things then went wrong at once, and
 * together they locked a newly approved curator out of the whole dev stack
 * (owner-reported 2026-08-14):
 *
 *  - `TwoFactorController::setup()` REQUIRED that service, so the page 500'd;
 *  - `TwoFactorSetupEnforcer` sent every not-yet-enrolled curator to it on
 *    every request, so the 500 was the only page they could reach.
 *
 * Unit-level and env-independent on purpose: `when@dev` does not apply to the
 * test environment, so a functional test here would exercise the provider-ON
 * path and prove nothing about the branch that broke.
 */
final class TwoFactorUnavailableTest extends TestCase
{
    private function policy(?TotpAuthenticatorInterface $totp): TwoFactorPolicy
    {
        return new TwoFactorPolicy(
            new RoleHierarchy(['ROLE_ADMIN' => ['ROLE_CURATOR'], 'ROLE_CURATOR' => ['ROLE_USER']]),
            $totp,
        );
    }

    private function curator(): User
    {
        return (new User())->setEmail('c@example.test')->setRoles(['ROLE_CURATOR']);
    }

    public function testACuratorIsNotSentToAnEnrolmentThatCannotBeCompleted(): void
    {
        $policy = $this->policy(null);

        self::assertTrue($policy->isMandatoryFor($this->curator()), '2FA is still the rule for curators');
        self::assertFalse($policy->isAvailable(), 'no provider, nothing to enrol in');
        self::assertFalse(
            $policy->requiresSetup($this->curator()),
            'redirecting here would trap the account rather than protect anything',
        );
    }

    public function testTheRuleIsUnchangedWhereverTheProviderIsOn(): void
    {
        $policy = $this->policy($this->createStub(TotpAuthenticatorInterface::class));

        self::assertTrue($policy->isAvailable());
        self::assertTrue(
            $policy->requiresSetup($this->curator()),
            'prod and staging keep demanding it, which is the whole point',
        );
    }

    public function testAPlainRiderIsNeverForcedEitherWay(): void
    {
        $rider = (new User())->setEmail('r@example.test')->setRoles([]);

        self::assertFalse($this->policy($this->createStub(TotpAuthenticatorInterface::class))->requiresSetup($rider));
        self::assertFalse($this->policy(null)->requiresSetup($rider));
    }
}
