<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Map;

use App\Entity\User;
use App\Map\MapPresets;
use App\Map\PresetInput;
use App\Service\UserDeletionService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A preset goes with the account (docs/specs/map-presets.md section 2 and
 * 6): the purge removes a rider's presets and nobody else's. Nothing public
 * derives from one, so there is nothing to keep and nothing to anonymise.
 */
final class MapPresetDeletionTest extends KernelTestCase
{
    public function testPurgingAnAccountRemovesItsPresetsAndNobodyElses(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $db = $c->get(Connection::class);
        $leaving = (new User())->setEmail('leaving-presets@test.test');
        $leaving->setPassword('x');
        $staying = (new User())->setEmail('staying-presets@test.test');
        $staying->setPassword('x');
        $em->persist($leaving);
        $em->persist($staying);
        $em->flush();
        $presets = $c->get(MapPresets::class);
        $input = PresetInput::parse(['name' => 'Commute', 'settings' => ['mode' => 'all', 'layers' => ['water'], 'overlays' => [], 'seasons' => [], 'bikes' => [], 'facets' => []]]);
        self::assertNotNull($input);
        $presets->save($leaving, $input);
        $presets->save($leaving, $input);
        $kept = $presets->save($staying, $input)->getId();
        $gone = (int) $leaving->getId();

        $c->get(UserDeletionService::class)->purge($leaving);
        $em->flush();

        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM map_preset WHERE user_id = ?', [$gone]));
        self::assertSame($kept, (int) $db->fetchOne('SELECT id FROM map_preset WHERE user_id = ?', [(int) $staying->getId()]));
    }
}
