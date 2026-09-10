<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Translation;

use App\Translation\Entity\TranslationOverlay;
use App\Translation\OverlayCatalogue;
use App\Translation\OverlayTranslator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\CacheWarmer\WarmableInterface;
use Symfony\Component\Translation\MessageCatalogueInterface;
use Symfony\Component\Translation\TranslatorBagInterface;
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
        $em->persist(new TranslationOverlay($entry, 'fr', 'Carte (overlay)', null, null, 1));
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
        $em->persist(new TranslationOverlay($entry, 'fr', 'Voir %name% ici', null, null, 1));
        $em->flush();

        static::getContainer()->get(OverlayCatalogue::class)->invalidate('fr');

        $t = static::getContainer()->get('translator');
        self::assertSame(
            'Voir Mont Ventoux ici',
            $t->trans('place.see_place', ['%name%' => 'Mont Ventoux'], 'messages', 'fr'),
        );
    }

    public function testEnglishOverlayBeatsYaml(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $entry = $this->findOrCreateEntry($em, 'nav.map', 'Map');
        $em->persist(new TranslationOverlay($entry, 'en', 'Map view', null, null, 1));
        $em->flush();
        static::getContainer()->get(OverlayCatalogue::class)->invalidate('en');

        $t = static::getContainer()->get('translator');
        self::assertSame('Map view', $t->trans('nav.map', [], 'messages', 'en'));
    }

    public function testGetCatalogueAppliesOverlaysWithoutMutatingInner(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $entry = $this->findOrCreateEntry($em, 'nav.map', 'Map');
        $em->persist(new TranslationOverlay($entry, 'fr', 'Carte catalogue', null, null, 1));
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

    public function testRichSanitizerStripsScriptKeepsBold(): void
    {
        $sanitizer = static::getContainer()->get('html_sanitizer.sanitizer.app.rich_translations');
        $clean = $sanitizer->sanitize('<script>alert(1)</script>Hi <b>there</b>');
        self::assertStringNotContainsString('<script>', $clean);
        self::assertStringContainsString('<b>there</b>', $clean);
    }
}
