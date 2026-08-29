<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\BugMarkdown;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The small markdown a bug report may use
 * (docs/specs/contact-and-support.md §15).
 *
 * The first four tests are the only reason this class exists rather than a
 * CommonMark dependency: the input is text a stranger typed, and it ends up on
 * a curator's screen and, once published, on a public page. Everything after
 * them is the feature.
 */
final class BugMarkdownTest extends KernelTestCase
{
    private function md(): BugMarkdown
    {
        self::bootKernel();

        return static::getContainer()->get(BugMarkdown::class);
    }

    // -- safety ----------------------------------------------------------

    public function testMarkupIsEscapedBeforeAnythingElseHappens(): void
    {
        $out = $this->md()->render('<script>alert(1)</script>');

        self::assertStringNotContainsString('<script', $out);
        self::assertStringContainsString('alert(1)', $out, 'the words survive, the tag does not');
    }

    /**
     * The classic: markup that tries to smuggle a handler through a tag.
     *
     * The WORD "onerror" is expected to survive, as text. What must not survive
     * is the tag it was an attribute of, and the `=` that bound it there.
     */
    public function testAnImageWithAnOnErrorHandlerIsJustText(): void
    {
        $out = $this->md()->render('<img src=x onerror="alert(1)">');

        self::assertStringNotContainsString('<img', $out);
        self::assertStringNotContainsString('onerror=', $out);
        self::assertStringContainsString('onerror', $out, 'the word reads as text');
    }

    /**
     * No links, on purpose.
     *
     * A report is not a place to publish a URL a curator will click. The text
     * still reads; it is simply not a target.
     */
    public function testAUrlIsNeverTurnedIntoALink(): void
    {
        $out = $this->md()->render('See https://example.test/pwn for details');

        self::assertStringNotContainsString('<a ', $out);
        self::assertStringContainsString('https://example.test/pwn', $out);
    }

    public function testMarkdownInsideACodeFenceStaysLiteral(): void
    {
        $out = $this->md()->render("```\n**not bold** <b>not bold</b>\n```");

        self::assertStringContainsString('<pre><code>', $out);
        self::assertStringNotContainsString('<strong>', $out);
        self::assertStringNotContainsString('<b>not bold</b>', $out);
    }

    // -- the feature -----------------------------------------------------

    public function testEmptyInputRendersNothing(): void
    {
        self::assertSame('', $this->md()->render(null));
        self::assertSame('', $this->md()->render("   \n  "));
    }

    public function testBoldItalicAndInlineCode(): void
    {
        $out = $this->md()->render('The **gate** is *locked*, run `git status`');

        self::assertStringContainsString('<strong>gate</strong>', $out);
        self::assertStringContainsString('<em>locked</em>', $out);
        self::assertStringContainsString('<code>git status</code>', $out);
    }

    /** Code first, so a shell line with asterisks in it survives. */
    public function testInlineCodeWinsOverBold(): void
    {
        $out = $this->md()->render('run `rm **` now');

        self::assertStringContainsString('<code>rm **</code>', $out);
        self::assertStringNotContainsString('<strong>', $out);
    }

    public function testABulletList(): void
    {
        $out = $this->md()->render("- open the map\n- click a place\n- it crashes");

        self::assertStringContainsString('<ul>', $out);
        self::assertSame(3, substr_count($out, '<li>'));
        self::assertStringNotContainsString('<p>', $out);
    }

    public function testANumberedList(): void
    {
        $out = $this->md()->render("1. open the map\n2. click a place");

        self::assertStringContainsString('<ol>', $out);
        self::assertSame(2, substr_count($out, '<li>'));
    }

    /** A paragraph that happens to open with a dash is still a paragraph. */
    public function testOneDashedLineAmongProseIsNotAList(): void
    {
        $out = $this->md()->render("The gate is locked\n- and has been since spring");

        self::assertStringNotContainsString('<ul>', $out);
        self::assertStringContainsString('<p>', $out);
    }

    public function testABlankLineStartsANewParagraphAndOneNewlineIsABreak(): void
    {
        $out = $this->md()->render("first\nstill first\n\nsecond");

        self::assertSame(2, substr_count($out, '<p>'));
        self::assertSame(1, substr_count($out, '<br'));
    }

    /** Somebody who forgets the closing fence keeps their paste. */
    public function testAnUnclosedFenceStillRenders(): void
    {
        $out = $this->md()->render("```\nFatal error: line 12");

        self::assertStringContainsString('<pre><code>', $out);
        self::assertStringContainsString('Fatal error: line 12', $out);
    }

    public function testAVeryLongReportIsCut(): void
    {
        $out = $this->md()->render(str_repeat('a', 20000));

        self::assertLessThan(9000, \strlen($out));
    }

    // -- the plain preview -----------------------------------------------

    /**
     * The desk list slices a body to fit a row, and a slice of markdown shows
     * a stray fence and a half-closed pair of asterisks.
     */
    public function testThePreviewStripsEveryMarker(): void
    {
        $out = $this->md()->plain("The gate is **locked**.\n\n- open the map\n- click it\n\n```\nFatal error\n```");

        self::assertStringNotContainsString('**', $out);
        self::assertStringNotContainsString('```', $out);
        self::assertStringNotContainsString('- ', $out);
        self::assertStringContainsString('The gate is locked.', $out);
        self::assertStringContainsString('open the map', $out);
    }

    public function testThePreviewIsOneLine(): void
    {
        $out = $this->md()->plain("first\n\nsecond\nthird");

        self::assertStringNotContainsString("\n", $out);
        self::assertSame('first second third', $out);
    }

    public function testAShortBodyIsNotEllipsised(): void
    {
        self::assertSame('The map stayed grey.', $this->md()->plain('The map stayed grey.'));
        self::assertSame('', $this->md()->plain(null));
    }

    public function testALongBodyIsCutOnAWordBoundary(): void
    {
        $out = $this->md()->plain(str_repeat('gate ', 100), 40);

        self::assertLessThanOrEqual(41, mb_strlen($out));
        self::assertStringEndsWith('…', $out);
        self::assertStringNotContainsString('gat…', $out, 'cut between words, not through one');
    }

    /** A pasted URL has no spaces, so the word boundary must not win there. */
    public function testAWordWithNoSpacesIsStillCut(): void
    {
        $out = $this->md()->plain(str_repeat('a', 300), 40);

        self::assertSame(41, mb_strlen($out));
        self::assertStringEndsWith('…', $out);
    }
}
