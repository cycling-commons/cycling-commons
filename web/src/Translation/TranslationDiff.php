<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Translation;

/**
 * Word-level was/now segments for curator translation history.
 *
 * @phpstan-type DiffSegment array{type: 'eq'|'del'|'ins', text: string}
 */
final class TranslationDiff
{
    /**
     * @return list<DiffSegment>
     */
    public static function words(string $from, string $to): array
    {
        if ($from === $to) {
            return [['type' => 'eq', 'text' => $to]];
        }

        $a = self::tokens($from);
        $b = self::tokens($to);
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

        $rev = [];
        $i = $n;
        $j = $m;
        while ($i > 0 || $j > 0) {
            if ($i > 0 && $j > 0 && $a[$i - 1] === $b[$j - 1]) {
                $rev[] = ['type' => 'eq', 'text' => $a[$i - 1]];
                --$i;
                --$j;
                continue;
            }
            if ($j > 0 && (0 === $i || $dp[$i][$j - 1] >= $dp[$i - 1][$j])) {
                $rev[] = ['type' => 'ins', 'text' => $b[$j - 1]];
                --$j;
                continue;
            }
            if ($i > 0) {
                $rev[] = ['type' => 'del', 'text' => $a[$i - 1]];
                --$i;
            }
        }

        return array_reverse($rev);
    }

    /**
     * @return list<string>
     */
    private static function tokens(string $s): array
    {
        $parts = preg_split('/\s+/u', trim($s), -1, PREG_SPLIT_NO_EMPTY);

        return false === $parts ? [] : array_values($parts);
    }
}
