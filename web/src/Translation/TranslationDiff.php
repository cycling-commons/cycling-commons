<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Translation;

/**
 * Word-level was/now segments, for curator translation history and for a
 * rider's own text contributions.
 *
 * @phpstan-type DiffSegment array{type: 'eq'|'del'|'ins', text: string}
 */
final class TranslationDiff
{
    /**
     * The words the start and the end share are one segment each, and only
     * the words between them are compared word by word, so a long text with
     * one change costs little.
     *
     * @return list<DiffSegment>
     */
    public static function words(string $from, string $to): array
    {
        if ($from === $to) {
            return [['type' => 'eq', 'text' => $to]];
        }

        $a = self::tokens($from);
        $b = self::tokens($to);
        $head = 0;
        while (isset($a[$head], $b[$head]) && $a[$head] === $b[$head]) {
            ++$head;
        }
        // The end is read backwards from after the shared start, so the two never overlap.
        $ra = array_reverse(\array_slice($a, $head));
        $rb = array_reverse(\array_slice($b, $head));
        $tail = 0;
        while (isset($ra[$tail], $rb[$tail]) && $ra[$tail] === $rb[$tail]) {
            ++$tail;
        }

        $out = [];
        if ($head > 0) {
            $out[] = ['type' => 'eq', 'text' => implode(' ', \array_slice($a, 0, $head))];
        }
        array_push($out, ...self::table(\array_slice($a, $head, \count($a) - $head - $tail), \array_slice($b, $head, \count($b) - $head - $tail)));
        if ($tail > 0) {
            $out[] = ['type' => 'eq', 'text' => implode(' ', \array_slice($a, \count($a) - $tail))];
        }

        return $out;
    }

    /**
     * The segments with each unchanged run cut to $keep words beside a
     * change, the cut marked "…": the words before the first change, the
     * words after the last, and the middle of a long run between two.
     * Neighbouring segments of one type join, so a changed phrase reads as one.
     *
     * @param list<DiffSegment> $segments
     *
     * @return list<DiffSegment>
     */
    public static function around(array $segments, int $keep = 6): array
    {
        $runs = [];
        foreach ($segments as $s) {
            $prev = array_key_last($runs);
            if (null !== $prev && $runs[$prev]['type'] === $s['type']) {
                $runs[$prev]['text'] .= ' '.$s['text'];
                continue;
            }
            $runs[] = $s;
        }

        $last = \count($runs) - 1;
        foreach ($runs as $i => $s) {
            if ('eq' !== $s['type'] || 0 === $last) {
                continue;
            }
            $w = self::tokens($s['text']);
            $before = implode(' ', \array_slice($w, 0, $keep));
            $after = implode(' ', \array_slice($w, -$keep));
            $runs[$i]['text'] = match (true) {
                0 === $i => \count($w) > $keep ? '… '.$after : $s['text'],
                $last === $i => \count($w) > $keep ? $before.' …' : $s['text'],
                default => \count($w) > 2 * $keep ? $before.' … '.$after : $s['text'],
            };
        }

        return $runs;
    }

    /**
     * Longest common subsequence of two word lists.
     *
     * @param list<string> $a
     * @param list<string> $b
     *
     * @return list<DiffSegment>
     */
    private static function table(array $a, array $b): array
    {
        $n = \count($a);
        $m = \count($b);
        $dp = [];
        for ($i = 0; $i <= $n; ++$i) {
            $dp[$i] = array_fill(0, $m + 1, 0);
        }
        for ($i = 1; $i <= $n; ++$i) {
            for ($j = 1; $j <= $m; ++$j) {
                $dp[$i][$j] = $a[$i - 1] === $b[$j - 1]
                    ? $dp[$i - 1][$j - 1] + 1
                    : max($dp[$i - 1][$j], $dp[$i][$j - 1]);
            }
        }

        // Walk the table back to the origin, then drain whichever side still has
        // words left. Splitting the tails out of the main loop keeps every index
        // inside a bound the static analysers can prove, which a single
        // "while one of them is left" loop does not.
        $rev = [];
        $i = $n;
        $j = $m;
        while ($i > 0 && $j > 0) {
            if ($a[$i - 1] === $b[$j - 1]) {
                $rev[] = ['type' => 'eq', 'text' => $a[$i - 1]];
                --$i;
                --$j;
                continue;
            }
            if ($dp[$i][$j - 1] >= $dp[$i - 1][$j]) {
                $rev[] = ['type' => 'ins', 'text' => $b[$j - 1]];
                --$j;
                continue;
            }
            $rev[] = ['type' => 'del', 'text' => $a[$i - 1]];
            --$i;
        }
        while ($j > 0) {
            $rev[] = ['type' => 'ins', 'text' => $b[$j - 1]];
            --$j;
        }
        while ($i > 0) {
            $rev[] = ['type' => 'del', 'text' => $a[$i - 1]];
            --$i;
        }

        return array_reverse($rev);
    }

    /**
     * @return list<string>
     */
    private static function tokens(string $s): array
    {
        $parts = preg_split('/\s+/u', trim($s), -1, PREG_SPLIT_NO_EMPTY);

        return false === $parts ? [] : $parts;
    }
}
