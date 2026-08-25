<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Translation;

use App\Translation\Entity\TranslationOverlay;
use App\Translation\OverlayCatalogue;
use App\Translation\OverlayCatalogueLoader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Per-locale overlay map from cache.app (translations.md §3).
 */
final class OverlayCatalogueTest extends KernelTestCase
{
    use FindsOrCreatesTranslationEntry;

    public function testEmptyWhenNoRows(): void
    {
        $catalogue = static::getContainer()->get(OverlayCatalogue::class);
        $catalogue->invalidate('fr');

        self::assertSame([], $catalogue->map('fr'));
    }

    public function testMapContainsOverlayAndSkipsAbsent(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $entry = $this->findOrCreateEntry($em, 'home.cta_map', 'Explore the map');
        $em->persist(new TranslationOverlay($entry, 'fr', 'Explorer la carte (overlay)', null, null));
        $em->flush();

        $catalogue = static::getContainer()->get(OverlayCatalogue::class);
        $catalogue->invalidate('fr');

        self::assertSame(
            ['home.cta_map' => 'Explorer la carte (overlay)'],
            $catalogue->map('fr'),
        );

        $entry->markAbsent();
        $em->flush();
        $catalogue->invalidate('fr');

        self::assertSame([], $catalogue->map('fr'));
    }

    public function testSecondMapDoesNotReloadAndInvalidateForcesReload(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $entry = $this->findOrCreateEntry($em, 'home.cta_map', 'Explore the map');
        $em->persist(new TranslationOverlay($entry, 'fr', 'Overlay FR', null, null));
        $em->flush();

        $loader = static::getContainer()->get(OverlayCatalogueLoader::class);
        $catalogue = static::getContainer()->get(OverlayCatalogue::class);
        $catalogue->invalidate('fr');

        $before = $loader->loads;
        self::assertSame(['home.cta_map' => 'Overlay FR'], $catalogue->map('fr'));
        self::assertSame($before + 1, $loader->loads);

        self::assertSame(['home.cta_map' => 'Overlay FR'], $catalogue->map('fr'));
        self::assertSame($before + 1, $loader->loads);

        $catalogue->invalidate('fr');
        self::assertSame(['home.cta_map' => 'Overlay FR'], $catalogue->map('fr'));
        self::assertSame($before + 2, $loader->loads);
    }

    public function testEnglishAndInvalidLocaleReturnEmptyWithoutLoading(): void
    {
        $loader = static::getContainer()->get(OverlayCatalogueLoader::class);
        $catalogue = static::getContainer()->get(OverlayCatalogue::class);
        $before = $loader->loads;

        self::assertSame([], $catalogue->map('en'));
        self::assertSame([], $catalogue->map('pt'));
        self::assertSame($before, $loader->loads);
    }
}
