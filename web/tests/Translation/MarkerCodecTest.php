<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Translation;

use App\Translation\MarkerCodec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The invisible marks around a translated string (translations.md §4.1).
 *
 * The fixture is shared with the browser decoder's Node test, so the two
 * languages cannot drift: whatever PHP writes here is what JavaScript reads.
 */
final class MarkerCodecTest extends TestCase
{
    /** @return iterable<string, array{int, bool, string, string}> */
    public static function fixture(): iterable
    {
        /** @var list<array{id: int, stale: bool, text: string, encoded: string}> $rows */
        $rows = json_decode(
            (string) file_get_contents(__DIR__.'/../js/fixtures/translate-marks.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        foreach ($rows as $row) {
            yield $row['id'].'/'.($row['stale'] ? 'stale' : 'fresh') => [
                $row['id'],
                $row['stale'],
                $row['text'],
                $row['encoded'],
            ];
        }
    }

    #[DataProvider('fixture')]
    public function testWrapMatchesTheSharedFixture(int $id, bool $stale, string $text, string $encoded): void
    {
        self::assertSame($encoded, MarkerCodec::wrap($id, $stale, $text));
    }

    #[DataProvider('fixture')]
    public function testStripRemovesExactlyTheMarks(int $id, bool $stale, string $text, string $encoded): void
    {
        self::assertSame($text, MarkerCodec::strip($encoded));
    }

    public function testIdsOutOfRangeAreNotMarked(): void
    {
        self::assertSame('x', MarkerCodec::wrap(0, false, 'x'));
        self::assertSame('x', MarkerCodec::wrap(MarkerCodec::MAX_ID + 1, false, 'x'));
    }

    public function testStripLeavesALoneZeroWidthCharacterAlone(): void
    {
        self::assertSame("a\u{200B}b", MarkerCodec::strip("a\u{200B}b"));
    }

    /**
     * The response net strips every body that is not `text/html`, and this
     * site serves binary ones. `preg_replace` with the `u` flag answers NULL
     * on malformed UTF-8, so a strip that trusted it would hand back an empty
     * body: no error, no log, a corrupt download.
     */
    public function testStripReturnsABodyItCannotScanUnchanged(): void
    {
        $notUtf8 = "abc\xC3\x28def";

        self::assertNull(preg_replace(MarkerCodec::PATTERN, '', $notUtf8), 'the guard has something to guard');
        self::assertSame($notUtf8, MarkerCodec::strip($notUtf8));
    }

    /**
     * `json_encode` without `JSON_UNESCAPED_UNICODE` rewrites every mark into
     * an ASCII `⁡...⁢` escape (Symfony's `JsonResponse` and
     * `boot.js.twig` both encode with options `15`, which lacks that flag), so
     * a body that never carried a raw U+2061 byte still carries the pattern in
     * its escaped form. `stripAllForms()` is the one place that catches both
     * (translations.md §4.1).
     */
    public function testStripAllFormsRemovesTheJsonEscapedForm(): void
    {
        $wrapped = MarkerCodec::wrap(812, false, 'hi');
        $json = json_encode(['a' => $wrapped], \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT);
        self::assertIsString($json);
        self::assertStringNotContainsString(MarkerCodec::START, $json, 'the escape hides the raw byte; that is the whole point of this test');

        $stripped = MarkerCodec::stripAllForms($json);

        self::assertSame('{"a":"hi"}', $stripped);
    }

    public function testStripAllFormsLeavesAJsonBodyWithNoMarksUnchanged(): void
    {
        $json = json_encode(['a' => 'plain', 'b' => 2], \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT);
        self::assertIsString($json);

        self::assertSame($json, MarkerCodec::stripAllForms($json));
    }

    /**
     * Same load-bearing guard as {@see testStripReturnsABodyItCannotScanUnchanged},
     * pinned again here because `stripAllForms()` runs two patterns through
     * `preg_replace()` instead of one: an array subject that fails on the
     * first (`/u`) pattern makes the whole call return NULL, not just skip
     * that one pattern, so the `?? $s` guard has to sit outside both.
     */
    public function testStripAllFormsReturnsABodyItCannotScanUnchanged(): void
    {
        $notUtf8 = "abc\xC3\x28def";

        self::assertNull(
            preg_replace([MarkerCodec::PATTERN, MarkerCodec::PATTERN_JSON], '', $notUtf8),
            'the guard has something to guard',
        );
        self::assertSame($notUtf8, MarkerCodec::stripAllForms($notUtf8));
    }

    public function testStripAllFormsLeavesALoneZeroWidthCharacterAlone(): void
    {
        self::assertSame("a\u{200B}b", MarkerCodec::stripAllForms("a\u{200B}b"));
    }
}
