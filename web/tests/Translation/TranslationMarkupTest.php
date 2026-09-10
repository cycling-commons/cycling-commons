<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Translation;

use App\Translation\TranslationMarkup;
use PHPUnit\Framework\TestCase;

/**
 * Markup in a proposed translation (docs/specs/translations.md §7).
 *
 * Catalogue strings carry markup and a translator sees it in the box they type
 * into. Many of them are not developers. The reason this check exists at all is
 * that BOTH failure modes are invisible without it: the sanitizer repairs an
 * unclosed tag and strips a disallowed one, so a broken translation would sit
 * in the catalogue rendering something nobody wrote, and nobody would be told.
 */
final class TranslationMarkupTest extends TestCase
{
    private const string EN = 'We hold this site to <b>WCAG 2.2, level AA</b>. That is a target.';

    // -- what must pass ---------------------------------------------------

    public function testPlainTextIsFine(): void
    {
        self::assertSame([], TranslationMarkup::check('Nous tenons ce site aux normes.'));
    }

    public function testBalancedAllowedTagsAreFine(): void
    {
        foreach ([
            'A <b>bold</b> claim.',
            'A <strong>strong</strong> one.',
            '<i>Italic</i> and <em>emphasis</em>.',
            'Run <code>git status</code>.',
            'One line<br>and another.',
            'One line<br />and another.',
            '<span class="x">Spanned</span>.',
            'See <a href="/terms">the terms</a>.',
            'Nested <b>bold with <i>italic</i> inside</b>.',
        ] as $value) {
            self::assertSame([], TranslationMarkup::check($value), $value);
            self::assertTrue(TranslationMarkup::isAcceptable($value), $value);
        }
    }

    // -- unbalanced -------------------------------------------------------

    /** The one that bleeds bold into the rest of the page. */
    public function testAnUnclosedTagIsAnError(): void
    {
        $problems = TranslationMarkup::check('Nous tenons ce site aux <b>WCAG 2.2.');

        self::assertSame('error', $problems[0]['level']);
        self::assertSame('unbalanced', $problems[0]['key']);
        self::assertSame('b', $problems[0]['tag']);
        self::assertFalse(TranslationMarkup::isAcceptable('Nous tenons ce site aux <b>WCAG 2.2.'));
    }

    public function testAStrayClosingTagIsAnError(): void
    {
        self::assertFalse(TranslationMarkup::isAcceptable('Texte</b> normal.'));
    }

    public function testCrossedTagsAreAnError(): void
    {
        self::assertFalse(TranslationMarkup::isAcceptable('<b>bold <i>both</b> italic</i>'));
    }

    /** `<br>` and `<br />` both close themselves; `</br>` is not a thing. */
    public function testAClosingVoidTagIsAnError(): void
    {
        self::assertTrue(TranslationMarkup::isAcceptable('one<br>two'));
        self::assertTrue(TranslationMarkup::isAcceptable('one<br />two'));
        self::assertFalse(TranslationMarkup::isAcceptable('one</br>two'));
    }

    // -- not allowed ------------------------------------------------------

    /**
     * A tag the sanitizer strips is refused HERE, where the translator can see
     * it. Accepting it would store words that never appear.
     */
    public function testATagOutsideTheAllowlistIsAnError(): void
    {
        foreach (['<script>alert(1)</script>', '<img src="x">', '<style>p{}</style>', '<div>x</div>', '<h1>x</h1>'] as $value) {
            self::assertFalse(TranslationMarkup::isAcceptable($value), $value);
        }
    }

    public function testTheErrorNamesTheTag(): void
    {
        $problems = TranslationMarkup::check('Voici une <marquee>image</marquee>.');

        $names = array_column($problems, 'tag');
        self::assertContains('marquee', $names);
    }

    /**
     * The allowlist is the sanitizer's list.
     *
     * If these drift, a translation is accepted here and stripped at render,
     * which is the exact failure this class exists to prevent.
     */
    public function testTheAllowlistMatchesTheSanitizerProfile(): void
    {
        $yaml = (string) file_get_contents(__DIR__.'/../../config/packages/html_sanitizer.yaml');
        $profile = substr($yaml, (int) strpos($yaml, 'app.rich_translations:'));
        $profile = substr($profile, 0, (int) strpos($profile, 'allowed_link_schemes'));

        foreach (TranslationMarkup::ALLOWED as $tag) {
            self::assertMatchesRegularExpression(
                '/^\s+'.preg_quote($tag, '/').':/m',
                $profile,
                sprintf('"%s" is allowed in translations but not by the sanitizer', $tag),
            );
        }
    }

    // -- drift, which warns and does not block ----------------------------

    public function testLosingAnEmphasisFromTheEnglishIsOnlyAWarning(): void
    {
        $problems = TranslationMarkup::check('Nous tenons ce site aux WCAG 2.2. Un objectif.', self::EN);

        self::assertCount(1, $problems);
        self::assertSame('warning', $problems[0]['level']);
        self::assertSame('tag_drift', $problems[0]['key']);
        self::assertSame('b', $problems[0]['tag']);
        // A warning must never stop somebody submitting: a language can move
        // its emphasis and sometimes it is right to.
        self::assertTrue(TranslationMarkup::isAcceptable('Nous tenons ce site aux WCAG 2.2.', self::EN));
    }

    public function testMatchingTheEnglishTagsRaisesNothing(): void
    {
        self::assertSame(
            [],
            TranslationMarkup::check('Nous tenons ce site aux <b>WCAG 2.2, niveau AA</b>. Un objectif.', self::EN),
        );
    }

    /** Errors are listed before advice: fix what blocks you first. */
    public function testErrorsSortAboveWarnings(): void
    {
        $problems = TranslationMarkup::check('<script>x</script> et <b>gras', self::EN);

        self::assertSame('error', $problems[0]['level']);
        self::assertSame('error', $problems[1]['level']);
    }

    public function testAStrayAngleBracketWarns(): void
    {
        $problems = TranslationMarkup::check('Moins de < 5 minutes.');

        self::assertSame('warning', $problems[0]['level']);
        self::assertSame('stray_angle', $problems[0]['key']);
        // Legal text, so it must not block.
        self::assertTrue(TranslationMarkup::isAcceptable('Moins de < 5 minutes.'));
    }
}
