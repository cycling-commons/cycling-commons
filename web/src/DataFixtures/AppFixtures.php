<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\User;
use App\World\Entity\Country;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Seeds the dev database with four ready-to-use demo accounts — one per role,
 * plus a public and an anonymous rider. All share the password `password1234`.
 *
 *   admin@example.test      ROLE_ADMIN    (2FA preset)      Belgium
 *   moderator@example.test  ROLE_CURATOR  (2FA preset)      Netherlands
 *   user@example.test       ROLE_USER     public profile    France
 *   anon@example.test       ROLE_USER     private profile    (no country)
 *
 * The elevated roles get a preset TOTP secret so login reaches /admin and
 * /moderate without enrolling in 2FA first. Countries are linked when the World
 * reference data is already present (run `app:world:import` first, as `make
 * setup` does); otherwise they are left null.
 *
 * @api Entry point for Doctrine fixtures; loaded by doctrine:fixtures:load.
 */
final class AppFixtures extends Fixture
{
    /** Shared, well-known dev password (meets the 12-char minimum). */
    public const string DEV_PASSWORD = 'password1234';

    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    #[\Override]
    public function load(ObjectManager $manager): void
    {
        $countries = $manager->getRepository(Country::class);
        $country = static fn (string $iso2): ?Country => $countries->findOneBy(['iso2' => $iso2]);

        $this->makeUser($manager, 'admin@example.test', 'Admin', ['ROLE_ADMIN'], $country('BE'), publicProfile: true, presetTotp: true);
        $this->makeUser($manager, 'moderator@example.test', 'Moderator', ['ROLE_CURATOR'], $country('NL'), publicProfile: true, presetTotp: true);
        $this->makeUser($manager, 'user@example.test', 'Rider', [], $country('FR'), publicProfile: true, presetTotp: false);
        $this->makeUser($manager, 'anon@example.test', 'Anonymous Rider', [], null, publicProfile: false, presetTotp: false);

        $manager->flush();
    }

    /** @param list<string> $roles */
    private function makeUser(
        ObjectManager $manager,
        string $email,
        string $displayName,
        array $roles,
        ?Country $country,
        bool $publicProfile,
        bool $presetTotp,
    ): void {
        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName($displayName);
        $user->setRoles($roles);
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable('2026-01-01'));
        $user->setPublicProfile($publicProfile);
        $user->setCountry($country);
        $user->setPassword($this->hasher->hashPassword($user, self::DEV_PASSWORD));

        // Preset the TOTP secret but leave 2FA *not enabled*: this satisfies the
        // TwoFactorSetupEnforcer (so elevated roles reach /admin and /moderate)
        // while keeping login password-only — no 2FA interstitial, no forced
        // enrolment. Demo convenience; a real elevated account would enrol 2FA.
        if ($presetTotp) {
            $user->setTotpSecret('JBSWY3DPEHPK3PXP');
        }

        $manager->persist($user);
    }
}
