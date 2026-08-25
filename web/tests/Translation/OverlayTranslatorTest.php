<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Translation;

use App\Translation\Entity\TranslationOverlay;
use App\Translation\OverlayCatalogue;
use App\Translation\OverlayCatalogueLoader;
use App\Translation\OverlayTranslator;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\CacheWarmer\WarmableInterface;
use Symfony\Component\Translation\MessageCatalogueInterface;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * OverlayTranslator merges overlays over YAML (translations.md §3).
 */
final class OverlayTranslatorTest extends KernelTestCase
{
    use FindsOrCreatesTranslationEntry;

    public function testYamlWinsWhenNoOverlay(): void
    {
        static::getContainer()->get(OverlayCatalogue::class)->invalidate('fr');

        $t = static::getContainer()->get('translator');
        $t->setLocale('fr');
        self::assertSame('Carte', $t->trans('nav.map', [], 'messages', 'fr'));
    }

    public function testOverlayBeatsYaml(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $entry = $this->findOrCreateEntry($em, 'nav.map', 'Map');
        $em->persist(new TranslationOverlay($entry, 'fr', 'Carte (overlay)', null, null));
        $em->flush();

        static::getContainer()->get(OverlayCatalogue::class)->invalidate('fr');

        $t = static::getContainer()->get('translator');
        self::assertSame('Carte (overlay)', $t->trans('nav.map', [], 'messages', 'fr'));
        self::assertNotSame('Carte', $t->trans('nav.map', [], 'messages', 'fr'));
    }

    public function testPercentNameInterpolation(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $entry = $this->findOrCreateEntry($em, 'place.see_place', 'See %name% on the map');
        $em->persist(new TranslationOverlay($entry, 'fr', 'Voir %name% ici', null, null));
        $em->flush();

        static::getContainer()->get(OverlayCatalogue::class)->invalidate('fr');

        $t = static::getContainer()->get('translator');
        self::assertSame(
            'Voir Mont Ventoux ici',
            $t->trans('place.see_place', ['%name%' => 'Mont Ventoux'], 'messages', 'fr'),
        );
    }

    public function testOverlayLocaleEnIsRejectedByCheck(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $entry = $this->findOrCreateEntry($em, 'nav.map', 'Map');

        $this->expectException(DriverException::class);
        $em->persist(new TranslationOverlay($entry, 'en', 'ROGUE', null, null));
        $em->flush();
    }

    public function testEnglishTransIgnoresStubbedOverlayMap(): void
    {
        $yamlEn = static::getContainer()->get('translator')->trans('nav.map', [], 'messages', 'en');

        $stub = new class(
            static::getContainer()->get(OverlayCatalogueLoader::class),
            static::getContainer()->get(CacheInterface::class),
        ) extends OverlayCatalogue {
            public function map(string $locale): array
            {
                return ['nav.map' => 'ROGUE FROM STUB'];
            }
        };

        /** @var TranslatorInterface&TranslatorBagInterface&LocaleAwareInterface $inner */
        $inner = static::getContainer()->get('App\Translation\OverlayTranslator.inner');
        $translator = new OverlayTranslator($inner, $stub);

        self::assertSame($yamlEn, $translator->trans('nav.map', [], 'messages', 'en'));
        self::assertNotSame('ROGUE FROM STUB', $translator->trans('nav.map', [], 'messages', 'en'));
    }

    public function testGetCatalogueAppliesOverlaysWithoutMutatingInner(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $entry = $this->findOrCreateEntry($em, 'nav.map', 'Map');
        $em->persist(new TranslationOverlay($entry, 'fr', 'Carte catalogue', null, null));
        $em->flush();

        static::getContainer()->get(OverlayCatalogue::class)->invalidate('fr');

        /** @var TranslatorBagInterface $t */
        $t = static::getContainer()->get('translator');
        $overlaid = $t->getCatalogue('fr');
        self::assertSame('Carte catalogue', $overlaid->get('nav.map', 'messages'));

        /** @var TranslatorBagInterface $inner */
        $inner = static::getContainer()->get('App\Translation\OverlayTranslator.inner');
        self::assertSame('Carte', $inner->getCatalogue('fr')->get('nav.map', 'messages'));
    }

    public function testWarmUpDelegatesToWarmableInner(): void
    {
        $inner = new class implements TranslatorInterface, TranslatorBagInterface, LocaleAwareInterface, WarmableInterface {
            public bool $warmed = false;

            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return $id;
            }

            public function getCatalogue(?string $locale = null): MessageCatalogueInterface
            {
                throw new \LogicException('unused');
            }

            public function getCatalogues(): array
            {
                return [];
            }

            public function setLocale(string $locale): void
            {
            }

            public function getLocale(): string
            {
                return 'en';
            }

            public function warmUp(string $cacheDir, ?string $buildDir = null): array
            {
                $this->warmed = true;

                return ['PreloadFromInner'];
            }
        };

        $overlays = $this->createStub(OverlayCatalogue::class);
        $translator = new OverlayTranslator($inner, $overlays);

        self::assertInstanceOf(WarmableInterface::class, $translator);
        self::assertSame(['PreloadFromInner'], $translator->warmUp('/tmp/cache', '/tmp/build'));
        self::assertTrue($inner->warmed);
    }

    public function testWarmUpReturnsEmptyWhenInnerNotWarmable(): void
    {
        $inner = new class implements TranslatorInterface, TranslatorBagInterface, LocaleAwareInterface {
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return $id;
            }

            public function getCatalogue(?string $locale = null): MessageCatalogueInterface
            {
                throw new \LogicException('unused');
            }

            public function getCatalogues(): array
            {
                return [];
            }

            public function setLocale(string $locale): void
            {
            }

            public function getLocale(): string
            {
                return 'en';
            }
        };

        $overlays = $this->createStub(OverlayCatalogue::class);
        $translator = new OverlayTranslator($inner, $overlays);

        self::assertSame([], $translator->warmUp('/tmp/cache'));
    }
}
