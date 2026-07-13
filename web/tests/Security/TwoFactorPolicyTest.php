<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Security\TwoFactorPolicy;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * #39: one hierarchy-aware policy decides who must enrol in 2FA — shared by the
 * login handler and the request enforcer, so they can never diverge.
 */
final class TwoFactorPolicyTest extends KernelTestCase
{
    private TwoFactorPolicy $policy;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->policy = static::getContainer()->get(TwoFactorPolicy::class);
    }

    private function user(array $roles): User
    {
        return (new User())->setEmail('p@test.test')->setRoles($roles);
    }

    public function testAdminIsMandatoryViaRoleHierarchy(): void
    {
        // ROLE_ADMIN implies ROLE_CURATOR via the hierarchy — the manual
        // in_array check this replaced would only catch a literal ROLE_ADMIN.
        self::assertTrue($this->policy->isMandatoryFor($this->user(['ROLE_ADMIN'])));
        self::assertTrue($this->policy->isMandatoryFor($this->user(['ROLE_CURATOR'])));
    }

    public function testPlainUserIsNotMandatory(): void
    {
        self::assertFalse($this->policy->isMandatoryFor($this->user([])));
    }

    public function testRequiresSetupWhenMandatoryAndTwoFaNotActive(): void
    {
        $u = $this->user(['ROLE_CURATOR']);
        self::assertTrue($this->policy->requiresSetup($u), 'no secret → must set up');

        // Secret present but not enabled → still required (matches scheb predicate).
        $u->setTotpSecret('JBSWY3DPEHPK3PXP');
        self::assertTrue($this->policy->requiresSetup($u));

        $u->setTwoFaEnabled(true);
        self::assertFalse($this->policy->requiresSetup($u), 'fully enrolled → nothing to do');
    }

    public function testPlainUserNeverRequiresSetup(): void
    {
        self::assertFalse($this->policy->requiresSetup($this->user([])));
    }
}
