<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\User;
use App\World\Entity\Country;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Dev demo accounts. All share `password1234`. Elevated roles get a preset TOTP secret.
 *
 * @api
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

        if ($presetTotp) {
            $user->setTotpSecret('JBSWY3DPEHPK3PXP');
            $user->setTwoFaEnabled(true);
        }

        $manager->persist($user);
    }
}
