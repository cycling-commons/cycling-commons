<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Routing;

use App\Routing\ActiveLocales;
use PHPUnit\Framework\TestCase;

/**
 * Which of the built languages this deployment actually serves
 * (dev-environment.md §7 i18n).
 */
final class ActiveLocalesTest extends TestCase
{
    private const array ENABLED = ['en', 'fr', 'nl', 'de', 'es'];

    public function testASubsetIsServedInTheCatalogueOrder(): void
    {
        // Posted out of order on purpose: the menu, the hreflang block and
        // the sitemap all read this list, and they must not reorder
        // themselves because somebody typed the env var differently.
        $locales = $this->activeLocales(['nl', 'en', 'de']);

        self::assertSame(['en', 'nl', 'de'], $locales->all());
        self::assertTrue($locales->isActive('nl'));
        self::assertFalse($locales->isActive('fr'));
    }

    public function testTheDefaultLocaleIsAlwaysServed(): void
    {
        // English is the source catalogue and every unprefixed route, so a
        // deployment cannot switch it off by leaving it out.
        self::assertSame(['en', 'nl'], $this->activeLocales(['nl'])->all());
    }

    public function testAnEmptyOrUnknownListLeavesOnlyTheDefault(): void
    {
        // Fail closed and loudly: a typo takes the site down to English,
        // which somebody notices, rather than quietly publishing a language
        // that was meant to stay hidden.
        self::assertSame(['en'], $this->activeLocales([])->all());
        self::assertSame(['en'], $this->activeLocales(['pt', 'it'])->all());
    }

    public function testSurroundingWhitespaceAndCaseAreForgiven(): void
    {
        self::assertSame(['en', 'nl'], $this->activeLocales([' NL ', ''])->all());
    }

    /** @param list<string> $configured */
    private function activeLocales(array $configured): ActiveLocales
    {
        return new ActiveLocales('en', self::ENABLED, $configured);
    }
}
