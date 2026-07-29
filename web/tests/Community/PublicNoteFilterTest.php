<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Community;

use App\Community\InvalidNoteException;
use App\Community\PublicNoteFilter;
use PHPUnit\Framework\TestCase;

/**
 * The only two stranger-writable free-text fields in this feature
 * (2026-07-29-country-requests-and-curator-signup-design.md §7).
 */
final class PublicNoteFilterTest extends TestCase
{
    public function testKeepsOrdinaryProse(): void
    {
        $f = new PublicNoteFilter();
        self::assertSame('I ride the Veluwe every week.', $f->clean('  I ride the Veluwe every week.  ', PublicNoteFilter::MAX_NOTE));
    }

    public function testRejectsUrls(): void
    {
        $f = new PublicNoteFilter();
        foreach (['see https://spam.example', 'www.spam.example', 'spam.example/path', 'mailto:me@example.com'] as $bad) {
            try {
                $f->clean($bad, PublicNoteFilter::MAX_NOTE);
                self::fail(sprintf('"%s" should have been rejected as a link', $bad));
            } catch (InvalidNoteException $e) {
                self::assertSame('link', $e->reason);
            }
        }
    }

    public function testRejectsOverLength(): void
    {
        $f = new PublicNoteFilter();
        $this->expectException(InvalidNoteException::class);
        $f->clean(str_repeat('a', PublicNoteFilter::MAX_NOTE + 1), PublicNoteFilter::MAX_NOTE);
    }

    public function testStripsZeroWidthAndBidiCharacters(): void
    {
        $f = new PublicNoteFilter();
        // U+200B zero width space, U+202E right-to-left override: the standard
        // trick for smuggling text past a human reviewer.
        self::assertSame('local rider', $f->clean("local\u{200B} \u{202E}rider", PublicNoteFilter::MAX_NOTE));
    }

    public function testStripsBidiIsolateCharacters(): void
    {
        $f = new PublicNoteFilter();
        // U+2066 left-to-right isolate (LRI), U+2069 pop directional isolate (PDI):
        // modern bidi controls used in Trojan-Source spoofing, must be stripped.
        self::assertSame('local rider', $f->clean("local \u{2066}rider\u{2069}", PublicNoteFilter::MAX_NOTE));
    }

    public function testNormalisesUnicodeAndCollapsesWhitespace(): void
    {
        $f = new PublicNoteFilter();
        // Composed vs decomposed "é" must not produce two different stored values.
        self::assertSame($f->clean("Li\u{00E8}ge", PublicNoteFilter::MAX_NOTE), $f->clean("Lie\u{0300}ge", PublicNoteFilter::MAX_NOTE));
        self::assertSame('two words', $f->clean("two \n\t words", PublicNoteFilter::MAX_NOTE));
    }

    public function testRejectsControlCharacters(): void
    {
        $f = new PublicNoteFilter();
        $this->expectException(InvalidNoteException::class);
        $f->clean("bell\u{0007}here", PublicNoteFilter::MAX_NOTE);
    }

    public function testAllowsProseWithColons(): void
    {
        $f = new PublicNoteFilter();
        // Prose like "Warning:sharp turn ahead" and "Note:this trail is closed"
        // must not be rejected as links. The scheme pattern now requires either
        // '://' or a known non-'//' scheme (mailto, tel, etc.) to avoid this.
        self::assertSame('Warning:sharp turn ahead', $f->clean('Warning:sharp turn ahead', PublicNoteFilter::MAX_NOTE));
        self::assertSame('Note:this trail is closed', $f->clean('Note:this trail is closed', PublicNoteFilter::MAX_NOTE));
    }

    public function testPinnedLinkFormsStillRejected(): void
    {
        $f = new PublicNoteFilter();
        // Verify the four pinned link forms are still rejected after the regex fix.
        foreach (['see https://spam.example', 'www.spam.example', 'spam.example/path', 'mailto:me@example.com'] as $bad) {
            try {
                $f->clean($bad, PublicNoteFilter::MAX_NOTE);
                self::fail(sprintf('"%s" should have been rejected as a link', $bad));
            } catch (InvalidNoteException $e) {
                self::assertSame('link', $e->reason, sprintf('"%s" should be rejected with reason "link"', $bad));
            }
        }
    }
}
