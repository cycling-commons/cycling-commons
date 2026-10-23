<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\RegionStory;
use PHPUnit\Framework\TestCase;

/**
 * A region's "History, nature and culture" text: paragraphs one per line,
 * sources cited in place on descriptive words, and the reader's language
 * first (moderation-and-contribution.md §3.1c).
 */
final class RegionStoryTest extends TestCase
{
    public function testEachLineIsAParagraphAndWhitespaceInsideFolds(): void
    {
        $text = RegionStory::normaliseText("  First   one.\r\n\r\n\r\nSecond\tone.\nThird one.  \n");

        self::assertSame("First one.\n\nSecond one.\n\nThird one.", $text);
        self::assertSame([
            [['text' => 'First one.', 'href' => null]],
            [['text' => 'Second one.', 'href' => null]],
            [['text' => 'Third one.', 'href' => null]],
        ], RegionStory::paragraphs($text));
    }

    public function testTheTextLinksTheWordsThatDescribeTheSource(): void
    {
        self::assertSame([[
            ['text' => 'The ', 'href' => null],
            ['text' => 'Stelling van Amsterdam', 'href' => 'https://www.stelling-amsterdam.nl'],
            ['text' => ' is a ring; [no address](ftp://x.test) and https://bare.test stay text.', 'href' => null],
        ]], RegionStory::paragraphs('The [Stelling van Amsterdam](https://www.stelling-amsterdam.nl) is a ring; [no address](ftp://x.test) and https://bare.test stay text.'));
    }

    public function testOnlyAnHttpsAddressLinks(): void
    {
        $text = 'The [Hunebedcentrum](http://www.hunebedcentrum.nl/) and the [Geopark](https://www.dehondsrug.nl/), and [again](http://www.hunebedcentrum.nl/).';

        self::assertSame([[
            ['text' => 'The [Hunebedcentrum](http://www.hunebedcentrum.nl/) and the ', 'href' => null],
            ['text' => 'Geopark', 'href' => 'https://www.dehondsrug.nl/'],
            ['text' => ', and [again](http://www.hunebedcentrum.nl/).', 'href' => null],
        ]], RegionStory::paragraphs($text), 'an http address stays as written');
        self::assertSame(['http://www.hunebedcentrum.nl/'], RegionStory::insecureLinks($text), 'named once, for the writer to change');
        self::assertSame([], RegionStory::insecureLinks('A bare http://x.test address is no link.'));
    }

    public function testTheReadableLengthCountsALinksWordsNotItsAddress(): void
    {
        self::assertSame(\mb_strlen("The Stelling rings.\n\nWind."), RegionStory::readableLength("The [Stelling](https://www.stelling-amsterdam.nl) rings.\n\nWind."));
        self::assertSame([['words' => 'Stelling', 'href' => 'https://www.stelling-amsterdam.nl']], RegionStory::links('The [Stelling](https://www.stelling-amsterdam.nl) rings.'));
    }

    public function testALinkNamedSourceOrHereIsGeneric(): void
    {
        self::assertSame('source', RegionStory::genericAnchor('The sluice ([source](https://www.hhnk.nl)).'));
        self::assertSame('Klik hier', RegionStory::genericAnchor("Fine [Beemster](https://a.test)\nAnd [Klik hier](https://b.test)."));
        self::assertNull(RegionStory::genericAnchor('The [inundation sluice in the Beemster](https://www.hhnk.nl).'));
    }

    public function testTheReaderGetsTheirLanguageThenEnglishThenAny(): void
    {
        $story = [
            'de' => ['text' => 'Deutsch.', 'userId' => 7, 'approvedBy' => 8],
            'en' => ['text' => "English.\n\nTwo.", 'userId' => null, 'approvedBy' => null],
        ];

        self::assertSame('de', RegionStory::resolve($story, 'de')['lang'] ?? null);
        $en = RegionStory::resolve($story, 'nl');
        self::assertNotNull($en);
        self::assertSame('en', $en['lang']);
        self::assertSame([[['text' => 'English.', 'href' => null]], [['text' => 'Two.', 'href' => null]]], $en['paragraphs']);
        self::assertSame('de', RegionStory::resolve(['de' => $story['de']], 'fr')['lang'] ?? null, 'any language when there is no English');
        self::assertNull(RegionStory::resolve(['nl' => ['text' => '  ']], 'nl'), 'a blank entry is no text');
        self::assertNull(RegionStory::resolve(null, 'en'));
    }

    public function testWithEntrySetsOneLanguageAndKeepsTheOthers(): void
    {
        $at = new \DateTimeImmutable('2026-10-20T10:00:00+00:00');
        $story = RegionStory::withEntry(['en' => ['text' => 'Kept.']], 'nl', 'Nieuw.', 5, 6, 9, $at);

        self::assertSame(['text' => 'Kept.'], $story['en']);
        self::assertSame(['text' => 'Nieuw.', 'userId' => 5, 'approvedBy' => 6, 'submissionId' => 9, 'at' => '2026-10-20T10:00:00+00:00'], $story['nl']);
    }
}
