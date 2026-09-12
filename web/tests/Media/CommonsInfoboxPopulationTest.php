<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Media;

use App\Media\Commons\CommonsApi;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Reading a headcount off a Wikipedia infobox, in the shapes that actually
 * occur (map-and-search.md §6.5).
 *
 * Every fixture here is the real structure of a real article, kept because
 * each one broke a simpler reader during the survey that justified this
 * fallback (2026-09-12): the first version reported `population_as_of = 2021`
 * as a population of 2021, the second read Hamburg's purchasing power per
 * head and called a city of 1.9 million a town of 29 thousand, and the third
 * took the year out of "op 1 januari 1992 tot heden".
 */
final class CommonsInfoboxPopulationTest extends TestCase
{
    /**
     * The plain shape: one label, one value, the year in the label.
     *
     * nl.wikipedia's Hoorn. The value cell carries a density in brackets after
     * the count, which is why a reader that vetoes a row for saying "/km²"
     * throws away the answer.
     */
    public function testReadsALabelledCountAndItsYear(): void
    {
        $html = '<table class="infobox"><tr><th>Inwoners (1 jan 2026)</th>'
            .'<td>76.211 (3739 inw./km²)</td></tr></table>';

        self::assertSame(['n' => 76211, 'year' => 2026], $this->read('nl', $html));
    }

    /**
     * A label that stacks four things over one cell, count first.
     *
     * nl.wikipedia's Gent. The label ends in "Bevolkingsdichtheid", so a
     * reader that vetoes on the presence of a density word loses the row; the
     * rule is that whichever word comes FIRST owns it.
     */
    public function testTheFirstWordInTheLabelDecidesWhatTheRowIs(): void
    {
        $html = '<table class="infobox">'
            .'<tr><th>Bevolking (bron: Statbel)</th></tr>'
            .'<tr><th>Inwoners – Mannen – Vrouwen – Bevolkingsdichtheid</th>'
            .'<td>274.042 (01/01/2026) 49,84% 50,16% 1736,97 inw./km²</td></tr>'
            .'</table>';

        self::assertSame(['n' => 274042, 'year' => 2026], $this->read('nl', $html));
    }

    /**
     * Money per head is not a headcount.
     *
     * de.wikipedia's Hamburg carries "Kaufkraft je Einwohner" above
     * "Einwohner", and both match the word the reader is looking for.
     */
    public function testSkipsPerCapitaMoneyAndTakesTheRealCount(): void
    {
        $html = '<table class="infobox">'
            .'<tr><th>Kaufkraft je Einwohner:</th><td>28.931 € (2024)</td></tr>'
            .'<tr><th>Einwohner:</th><td>1.869.473 (31. Dez. 2025)</td></tr>'
            .'</table>';

        self::assertSame(['n' => 1869473, 'year' => 2025], $this->read('de', $html));
    }

    /**
     * The English shape: a header row, then the number one row down.
     *
     * en.wikipedia's Banff. "Population (2021)" has no value of its own, and
     * the count arrives on the "• Total" row beneath it.
     */
    public function testFollowsAHeaderRowToTheTotalBeneathIt(): void
    {
        $html = '<table class="infobox">'
            .'<tr><th colspan="2">Population (2021)</th></tr>'
            .'<tr><th>• Total</th><td>8,305</td></tr>'
            .'<tr><th>• Density</th><td>1,000/km<sup>2</sup></td></tr>'
            .'</table>';

        self::assertSame(['n' => 8305, 'year' => 2021], $this->read('en', $html));
    }

    /**
     * A four-digit year alone in a cell is a year, not a village.
     *
     * nl.wikipedia labels a section "Inwoners van jaar tot jaar op 1 januari
     * 1992 tot heden", and 1992 passes every other test for a headcount.
     */
    public function testRefusesABareYearAsAPopulation(): void
    {
        $html = '<table class="infobox">'
            .'<tr><th>Inwoners van jaar tot jaar op 1 januari 1992 tot heden</th><td>1992</td></tr>'
            .'</table>';

        self::assertNull($this->read('nl', $html));
    }

    /**
     * An article with no infobox answers nothing, and that is correct.
     *
     * nl.wikipedia's Zwaag, the village the owner reported this against: it
     * has no table at all, so no source can be blamed for the blank.
     */
    public function testAnArticleWithNoInfoboxAnswersNothing(): void
    {
        self::assertNull($this->read('nl', '<p>Zwaag is een dorp in Noord-Holland.</p>'));
    }

    /** A language with no rules of its own reads the English words. */
    public function testFallsBackToTheEnglishLabelsForAnUnlistedLanguage(): void
    {
        $html = '<table class="infobox"><tr><th>Population</th><td>1 234</td></tr></table>';

        self::assertSame(['n' => 1234, 'year' => null], $this->read('pt', $html));
    }

    public function testRejectsSomethingThatIsNotALanguageCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->read('not-a-lang', '');
    }

    /**
     * @return array{n: int, year: ?int}|null
     */
    private function read(string $lang, string $html): ?array
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            json_encode(['parse' => ['text' => $html]], \JSON_THROW_ON_ERROR),
            ['http_code' => 200],
        ));

        return (new CommonsApi($client, 'CyclingCommons-test/1.0'))->infoboxPopulation($lang, 'Whatever');
    }
}
