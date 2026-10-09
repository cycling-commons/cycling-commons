<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Legal;

use App\Legal\LegalVersions;
use App\Legal\TermsIncludedTexts;
use App\Legal\TermsVersions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The texts the terms include by reference follow the terms' own 30-day rule
 * (docs/specs/translations.md §6.2).
 *
 * Each included text is pinned by hash to the terms version that last
 * accepted it. A text that changed without a new pin fails here, and the
 * message says what to do: a significant change needs a significant terms
 * version announced LegalVersions::NOTICE_DAYS days ahead; an editorial one
 * is re-pinned with a written reason.
 *
 * Files outside web/ are skipped where only web/ is mounted (the dev
 * container); CI has the whole checkout and checks every one.
 */
final class TermsIncludedTextsTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function texts(): iterable
    {
        foreach (array_keys(TermsIncludedTexts::PINS) as $text) {
            yield $text => [$text];
        }
    }

    #[DataProvider('texts')]
    public function testTheTextIsTheOneTheTermsLastAccepted(string $text): void
    {
        $current = TermsIncludedTexts::currentHash($text);
        if (null === $current) {
            if (str_starts_with($text, 'web/')) {
                self::fail("{$text} is pinned as a text the terms include, but it does not exist.");
            }
            self::markTestSkipped("{$text} is outside web/ and not present in this checkout");
        }

        $pin = TermsIncludedTexts::PINS[$text][0];
        self::assertSame($pin['sha256'], $current, TermsIncludedTexts::changedMessage($text, $current));
    }

    #[DataProvider('texts')]
    public function testEveryPinNamesATermsVersionAndARulingThatHolds(string $text): void
    {
        $versions = array_column(TermsVersions::all(), null, 'number');
        $today = (new \DateTimeImmutable())->format('Y-m-d');
        // Read as the terms read them: the declared type, not the literal constant, which names the kinds in use today only.
        $pins = TermsVersions::includedTexts()[$text];
        self::assertNotEmpty($pins);

        $previous = null;
        foreach ($pins as $i => $pin) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $pin['sha256'], "{$text} pin {$i}: a sha256");
            self::assertArrayHasKey($pin['terms'], $versions, "{$text} pin {$i}: terms version {$pin['terms']} exists");
            self::assertLessThanOrEqual($today, $pin['date'], "{$text} pin {$i}: dated today or earlier");
            self::assertGreaterThanOrEqual(10, mb_strlen(trim($pin['reason'])), "{$text} pin {$i}: a written reason");
            self::assertStringNotContainsString('<why', $pin['reason'], "{$text} pin {$i}: the reason is written, not the placeholder");
            $version = $versions[$pin['terms']];

            match ($pin['kind']) {
                TermsIncludedTexts::KIND_ACCEPTED => self::assertSame(\count($pins) - 1, $i, "{$text}: only the oldest pin is the text as first accepted"),
                TermsIncludedTexts::KIND_SIGNIFICANT => self::assertTrue(
                    $version['significant'] && $version['number'] >= LegalVersions::NOTICE_RULE_FROM && $version['effective'] <= $pin['date'],
                    "{$text} pin {$i}: a significant change rests on a significant terms version from ".LegalVersions::NOTICE_RULE_FROM.' on, and lands on or after the day it applies ('.$version['effective'].')',
                ),
                TermsIncludedTexts::KIND_EDITORIAL => self::assertTrue(
                    $version['effective'] <= $pin['date'],
                    "{$text} pin {$i}: an editorial change is accepted under the terms version that applied that day",
                ),
                default => self::fail("{$text} pin {$i}: unknown kind {$pin['kind']}"),
            };

            if (null !== $previous) {
                self::assertGreaterThanOrEqual($pin['date'], $previous['date'], "{$text}: pins are newest first");
                self::assertGreaterThanOrEqual($pin['terms'], $previous['terms'], "{$text}: pins are newest first");
            }
            $previous = $pin;
        }
    }

    /**
     * Every repository text the licences page or the terms link is pinned, so
     * a new link cannot add an unpinned text to the deal.
     */
    public function testEveryRepositoryTextTheLicencesPageAndTheTermsLinkIsPinned(): void
    {
        $linked = [];
        foreach (['templates/pages/licenses.html.twig', 'templates/pages/terms.html.twig'] as $template) {
            preg_match_all('~github\.com/cycling-commons/cycling-commons/blob/main/([^"#?\s]+)~', (string) file_get_contents(\dirname(__DIR__, 2).'/'.$template), $m);
            $linked = [...$linked, ...$m[1]];
        }

        self::assertNotEmpty($linked);
        foreach (array_unique($linked) as $path) {
            self::assertArrayHasKey($path, TermsIncludedTexts::PINS, "{$path} is linked from the licences page or the terms but not pinned in TermsIncludedTexts");
        }
        $page = ['web/templates/pages/licenses.html.twig'];
        foreach (['en', 'fr', 'nl', 'de', 'es'] as $locale) {
            $page[] = "web/translations/licenses.{$locale}.yaml";
        }
        foreach ($page as $text) {
            self::assertArrayHasKey($text, TermsIncludedTexts::PINS, "the licences page itself ({$text}) is pinned");
        }
    }

    public function testTheMessageSaysWhatToDo(): void
    {
        $message = TermsIncludedTexts::changedMessage('TRADEMARK.md', str_repeat('a', 64));

        self::assertStringContainsString('app:legal:announce', $message);
        self::assertStringContainsString((string) LegalVersions::NOTICE_DAYS, $message);
        self::assertStringContainsString('editorial', $message);
        self::assertStringContainsString(str_repeat('a', 64), $message, 'the new hash, ready to pin');
    }

    public function testTheTermsReadThePins(): void
    {
        self::assertSame(TermsIncludedTexts::PINS, TermsVersions::includedTexts());
    }

    public function testATranslationSubtreeIsHashedByContentNotByLayout(): void
    {
        $a = TermsIncludedTexts::subtreeHash(['b' => '2', 'a' => ['y' => '1', 'x' => '0']]);
        $b = TermsIncludedTexts::subtreeHash(['a' => ['x' => '0', 'y' => '1'], 'b' => '2']);
        $c = TermsIncludedTexts::subtreeHash(['a' => ['x' => '0', 'y' => '1'], 'b' => '3']);

        self::assertSame($a, $b, 'key order does not count');
        self::assertNotSame($a, $c, 'a changed value does');
    }
}
