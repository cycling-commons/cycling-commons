<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * The small markdown a bug report is allowed to use.
 *
 * **Why a subset and not a CommonMark library.** The input is text a stranger
 * typed, rendered on a curator's screen and, once a curator publishes it, on
 * the public known-issues page. A full parser brings raw-HTML passthrough,
 * reference links, images, autolinks and HTML entities, and every one of those
 * is a decision somebody has to review. What a bug report actually needs is six
 * things, and six things fit in one readable file.
 *
 * **Order matters, and it is escape first.** Everything is passed through
 * {@see htmlspecialchars()} before a single markdown rule runs, so a stranger's
 * `<script>` is already `&lt;script&gt;` by the time anything looks for
 * asterisks. The markdown rules then add tags to text that can no longer
 * contain any. The sanitizer at the end is the third layer, not the first: it
 * catches a mistake in the rules above, and it is what makes the output safe to
 * mark `is_safe` in Twig.
 *
 * **What is supported**, and nothing else:
 *
 * - fenced code blocks, ```
 * - `inline code`
 * - **bold** and *italic*
 * - bullet lists (`-` or `*`) and numbered lists
 * - blank line is a paragraph, single newline is a line break
 *
 * Deliberately absent: images (a bug report that renders a remote image is a
 * tracking pixel on a curator's screen), links (a report is not a place to
 * publish a URL somebody else will click; the plain text is still readable),
 * headings and tables (nobody writing a bug uses them, and they would let a
 * report shout on the public page).
 *
 * @see docs/specs/contact-and-support.md §15
 *
 * @api
 */
final class BugMarkdown
{
    /** Long enough for a stack trace, short enough that one report cannot be a page. */
    private const int MAX_LENGTH = 8000;

    public function __construct(
        #[Target('app.bug_markdown')]
        private readonly HtmlSanitizerInterface $sanitizer,
    ) {
    }

    public function render(?string $source): string
    {
        if (null === $source || '' === trim($source)) {
            return '';
        }

        // Escape FIRST. Every rule below adds tags to text that can no longer
        // contain any of its own.
        $text = htmlspecialchars(
            mb_substr(str_replace(["\r\n", "\r"], "\n", $source), 0, self::MAX_LENGTH),
            \ENT_QUOTES | \ENT_SUBSTITUTE,
            'UTF-8',
        );

        $html = '';
        foreach ($this->blocks($text) as $block) {
            $html .= $block;
        }

        return $this->sanitizer->sanitize($html);
    }

    /**
     * A one-line preview with the markdown markers taken off.
     *
     * The desk list slices a body to fit a row, and a slice of markdown is
     * neither markdown nor prose: it shows a stray ``` and a half-closed
     * `**`. Rendering it instead would be worse, because a 160-character cut
     * through a fence produces broken HTML.
     *
     * So the preview is text. It strips the markers, folds every newline into
     * a space, and cuts on a word boundary. Nothing here is a safety measure:
     * the result is escaped by Twig like any other string.
     */
    public function plain(?string $source, int $length = 160): string
    {
        if (null === $source || '' === trim($source)) {
            return '';
        }

        $text = str_replace(["\r\n", "\r"], "\n", $source);
        // Fence markers, then list bullets at the start of a line, then the
        // inline pairs. Order matters only in that fences go first, so their
        // contents are treated as ordinary words.
        $text = (string) preg_replace('/^\s*```.*$/m', '', $text);
        $text = (string) preg_replace('/^\s{0,3}(?:[-*]|\d{1,3}[.)])\s+/m', '', $text);
        $text = str_replace(['**', '`'], '', $text);
        $text = (string) preg_replace('/(?<!\*)\*(?!\*)/', '', $text);
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        if (mb_strlen($text) <= $length) {
            return $text;
        }

        $cut = mb_substr($text, 0, $length);
        $space = mb_strrpos($cut, ' ');
        // Only honour the word boundary if it is not so early that the preview
        // becomes useless: a 200-character URL has no spaces in it at all.
        if (false !== $space && $space > (int) ($length * 0.6)) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut).'…';
    }

    /**
     * Split into code fences, lists and paragraphs, in that precedence.
     *
     * Fences win outright: text inside one is shown exactly as typed, which is
     * the whole reason somebody pastes a stack trace between backticks.
     *
     * @return list<string>
     */
    private function blocks(string $text): array
    {
        /** @var list<string> $out */
        $out = [];
        $lines = explode("\n", $text);
        /** @var list<string> $buffer the current paragraph or list, unflushed */
        $buffer = [];
        /** @var list<string>|null $fence lines inside an open ``` block */
        $fence = null;

        $flush = function () use (&$buffer, &$out): void {
            if ([] !== $buffer) {
                $out[] = $this->paragraphOrList($buffer);
                $buffer = [];
            }
        };

        foreach ($lines as $line) {
            if (null !== $fence) {
                if (str_starts_with(trim($line), '```')) {
                    $out[] = '<pre><code>'.implode("\n", $fence).'</code></pre>';
                    $fence = null;
                } else {
                    $fence[] = $line;
                }
                continue;
            }

            if (str_starts_with(trim($line), '```')) {
                $flush();
                $fence = [];
                continue;
            }

            if ('' === trim($line)) {
                $flush();
                continue;
            }

            $buffer[] = $line;
        }

        // An unclosed fence is a person who forgot the second one, not an
        // attack. Render what they typed rather than losing it.
        if (null !== $fence && [] !== $fence) {
            $out[] = '<pre><code>'.implode("\n", $fence).'</code></pre>';
        }
        $flush();

        return $out;
    }

    /**
     * @param list<string> $lines
     */
    private function paragraphOrList(array $lines): string
    {
        $bullets = 0;
        $numbers = 0;
        foreach ($lines as $line) {
            if (1 === preg_match('/^\s{0,3}[-*]\s+\S/', $line)) {
                ++$bullets;
            } elseif (1 === preg_match('/^\s{0,3}\d{1,3}[.)]\s+\S/', $line)) {
                ++$numbers;
            }
        }

        // A block is a list only if every line in it is an item. A paragraph
        // that happens to open with a dash stays a paragraph.
        $count = \count($lines);
        if ($bullets === $count || $numbers === $count) {
            $tag = $bullets === $count ? 'ul' : 'ol';
            $items = '';
            foreach ($lines as $line) {
                $items .= '<li>'.$this->inline((string) preg_replace('/^\s{0,3}(?:[-*]|\d{1,3}[.)])\s+/', '', $line)).'</li>';
            }

            return '<'.$tag.'>'.$items.'</'.$tag.'>';
        }

        return '<p>'.implode('<br />', array_map($this->inline(...), $lines)).'</p>';
    }

    /**
     * Inline code, then bold, then italic.
     *
     * Code first so that `**not bold**` inside backticks stays literal, which
     * is what somebody pasting a shell line expects.
     */
    private function inline(string $line): string
    {
        $line = (string) preg_replace('/`([^`\n]+)`/', '<code>$1</code>', $line);
        $line = (string) preg_replace('/\*\*([^*\n]+)\*\*/', '<strong>$1</strong>', $line);

        return (string) preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/', '<em>$1</em>', $line);
    }
}
