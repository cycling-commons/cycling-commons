<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\World;

use App\World\CountryInText;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * A country's name inside a sentence ({@see CountryInText}): "Nobody is
 * curating the USA yet", never "Nobody is curating United States yet".
 */
final class CountryInTextTest extends KernelTestCase
{
    private function names(): CountryInText
    {
        return static::getContainer()->get(CountryInText::class);
    }

    public function testACountryThatTakesAnArticleReadsWithIt(): void
    {
        self::assertSame('the USA', $this->names()->name('US', 'en'));
        self::assertSame('The USA', $this->names()->name('us', 'en', true), 'a sentence that opens with it');
        self::assertSame('de VS', $this->names()->name('US', 'nl'));
        self::assertSame('De VS', $this->names()->name('US', 'nl', true));
        self::assertSame('Nederland', $this->names()->name('NL', 'nl'));
    }

    public function testEveryOtherCountryReadsItsPlainName(): void
    {
        self::assertSame('Belgium', $this->names()->name('BE', 'en'));
        self::assertSame('België', $this->names()->name('BE', 'nl'));
        self::assertSame('XX', $this->names()->name('XX', 'en'), 'an unknown code stays the code');
    }

    public function testALanguageWaitingForReviewKeepsThePlainName(): void
    {
        self::assertSame('États-Unis', $this->names()->name('US', 'fr'), 'never the English fallback in a French sentence');
    }

    /** The list in code and the catalogue entries are the same set, in every language. */
    public function testEveryListedCodeHasASentenceNameInEveryLanguage(): void
    {
        foreach (['en', 'nl', 'fr', 'de', 'es'] as $locale) {
            /** @var array{country_in_text: array<string, string>} $catalogue */
            $catalogue = Yaml::parseFile(\dirname(__DIR__, 2).'/translations/messages.'.$locale.'.yaml');
            $codes = array_keys($catalogue['country_in_text']);
            sort($codes);
            self::assertSame(CountryInText::CODES, $codes, $locale);
        }
    }
}
