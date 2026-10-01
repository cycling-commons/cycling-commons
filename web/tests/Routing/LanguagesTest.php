<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Routing;

use App\Routing\ActiveLocales;
use App\Routing\Languages;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The one provider for every list of languages (dev-environment.md §7 i18n):
 * what a dropdown offers is what this deployment serves, named in each
 * language's own words, and the same everywhere.
 */
final class LanguagesTest extends KernelTestCase
{
    private const array BUILT = ['en', 'fr', 'nl', 'de', 'es'];

    public function testOptionsAreTheServedSubsetInTheBuildOrder(): void
    {
        // Posted out of order: the list follows the build, never the variable.
        self::assertSame(
            ['en' => 'English', 'nl' => 'Nederlands', 'de' => 'Deutsch'],
            $this->languages(['de', 'nl'])->options(),
        );
    }

    public function testTheDefaultLanguageIsAlwaysOffered(): void
    {
        self::assertSame(['en' => 'English', 'nl' => 'Nederlands'], $this->languages(['nl'])->options());
    }

    public function testAnEmptyOrUnknownListOffersOnlyTheDefault(): void
    {
        self::assertSame(['en' => 'English'], $this->languages([])->options());
        self::assertSame(['en' => 'English'], $this->languages(['pt', 'xx'])->options());
    }

    public function testNamesCoverEveryBuiltLanguageServedOrNot(): void
    {
        $languages = $this->languages(['nl']);

        self::assertSame(
            ['en' => 'English', 'fr' => 'Français', 'nl' => 'Nederlands', 'de' => 'Deutsch', 'es' => 'Español'],
            $languages->names(),
        );
        self::assertSame('Français', $languages->name('fr'), 'a stored text in a hidden language keeps its name');
        self::assertSame('pt', $languages->name('pt'), 'an unknown code shows as itself');
    }

    public function testOnlyAServedLanguageMayBeWritten(): void
    {
        $languages = $this->languages(['nl']);

        self::assertTrue($languages->isServed('en'));
        self::assertTrue($languages->isServed('nl'));
        self::assertFalse($languages->isServed('fr'));
        self::assertFalse($languages->isServed(''));
        self::assertSame('en', $languages->defaultCode());
    }

    /**
     * A language added to `framework.enabled_locales` without a name here
     * would show its bare code in every menu.
     */
    public function testEveryLanguageTheBuildCarriesHasAName(): void
    {
        self::bootKernel();
        /** @var list<string> $enabled */
        $enabled = self::getContainer()->getParameter('kernel.enabled_locales');
        $names = $this->languages($enabled, $enabled)->names();

        self::assertSame($enabled, array_keys($names));
        foreach ($names as $code => $name) {
            self::assertNotSame($code, $name, sprintf('"%s" has no name in Languages::ENDONYMS', $code));
        }
    }

    /**
     * @param list<string> $configured CC_ACTIVE_LOCALES
     * @param list<string> $built
     */
    private function languages(array $configured, array $built = self::BUILT): Languages
    {
        return new Languages(new ActiveLocales('en', $built, $configured), $built);
    }
}
