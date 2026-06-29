<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Seeds the dev database with two reference accounts:
 *
 *   curator@example.test  ROLE_CURATOR  (totpSecret preset — can reach /moderate without 2FA enrolment)
 *   rider@example.test    ROLE_USER     (plain user)
 *
 * Load with: php bin/console doctrine:fixtures:load --no-interaction
 *
 * @api Entry point for Doctrine fixtures; loaded by doctrine:fixtures:load.
 */
final class AppFixtures extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    #[\Override]
    public function load(ObjectManager $manager): void
    {
        // ── Curator ────────────────────────────────────────────────────────────
        // totpSecret is preset so that `php bin/console doctrine:fixtures:load`
        // + login reaches /moderate without enrolling in 2FA first.
        $curator = new User();
        $curator->setEmail('curator@example.test');
        $curator->setDisplayName('Demo Curator');
        $curator->setEmailVerified(true);
        $curator->setEmailVerifiedAt(new \DateTimeImmutable('2026-01-01'));
        $curator->setRoles(['ROLE_CURATOR']);
        $curator->setPassword($this->hasher->hashPassword($curator, 'curator-dev-pass!'));
        $curator->setTotpSecret('JBSWY3DPEHPK3PXP');
        $curator->setTwoFaEnabled(true);
        $manager->persist($curator);

        // ── Plain rider ────────────────────────────────────────────────────────
        $rider = new User();
        $rider->setEmail('rider@example.test');
        $rider->setDisplayName('Demo Rider');
        $rider->setEmailVerified(true);
        $rider->setEmailVerifiedAt(new \DateTimeImmutable('2026-01-01'));
        $rider->setRoles([]);
        $rider->setPassword($this->hasher->hashPassword($rider, 'rider-dev-pass!'));
        $manager->persist($rider);

        $manager->flush();
    }
}
