<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * @api Entry point for Doctrine fixtures; loaded by doctrine:fixtures:load.
 */
final class AppFixtures extends Fixture
{
    #[\Override]
    public function load(ObjectManager $manager): void
    {
        // Fixtures will be added in Task 6.
        $manager->flush();
    }
}
