<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Service;

/**
 * The geometry of a column chart for an admin page, drawn as inline SVG by
 * the template: no script, no library, nothing fetched from elsewhere. One or
 * more series stack bottom-up in each band; a reference line (a limit) is
 * drawn when the data come within half of it, so a far-off limit does not
 * flatten the bars. Columns are at most 24 px wide with a 4 px rounded top
 * and a 2 px gap between stacked segments; ticks are clean numbers, compact
 * from 1,000 (1K).
 *
 * @api
 */
final class AdminBarChart
{
    public const int WIDTH = 720;

    public const int HEIGHT = 220;

    private const int LEFT = 44;

    private const int RIGHT = 12;

    private const int TOP = 18;

    private const int BOTTOM = 26;

    private const float BAR_MAX = 24.0;

    private const float RADIUS = 4.0;

    private const float GAP = 2.0;

    private const int LABELS_MAX = 8;

    /**
     * @param list<string>               $labels one per band, left to right
     * @param array<string, list<float>> $series stacked bottom-up, by series id, one value per band
     *
     * @return array{width: int, height: int, plot: array{x: float, y: float, w: float, h: float}, bars: list<array{index: int, x: float, w: float, bx: float, bw: float, total: float, segments: list<array{series: string, value: float, d: string}>}>, ticks: list<array{y: float, label: string}>, limit: array{y: float, value: float}|null, xLabels: list<array{x: float, text: string}>, max: float}
     */
    public static function build(array $labels, array $series, ?float $limit = null): array
    {
        $n = \count($labels);
        $plot = ['x' => (float) self::LEFT, 'y' => (float) self::TOP, 'w' => (float) (self::WIDTH - self::LEFT - self::RIGHT), 'h' => (float) (self::HEIGHT - self::TOP - self::BOTTOM)];
        $totals = [];
        for ($i = 0; $i < $n; ++$i) {
            $totals[$i] = array_sum(array_map(static fn (array $values): float => (float) ($values[$i] ?? 0), $series));
        }
        $max = [] === $totals ? 0.0 : max($totals);
        $line = $limit ?? 0.0;
        $showLimit = $line > 0 && $max >= $line / 2;
        $top = self::niceTop(max($max, $showLimit ? $line : 0.0, 1.0));
        $y = static fn (float $v): float => round($plot['y'] + $plot['h'] - $v / $top * $plot['h'], 2);

        $band = $n > 0 ? $plot['w'] / $n : $plot['w'];
        $w = min(self::BAR_MAX, max(2.0, $band * 0.7));
        $bars = [];
        for ($i = 0; $i < $n; ++$i) {
            $x = round($plot['x'] + $band * $i + ($band - $w) / 2, 2);
            $base = 0.0;
            $segments = [];
            $ids = array_keys($series);
            $last = null;
            foreach ($ids as $k => $id) {
                if ((float) ($series[$id][$i] ?? 0) > 0) {
                    $last = $k;
                }
            }
            foreach ($ids as $k => $id) {
                $v = (float) ($series[$id][$i] ?? 0);
                if ($v <= 0) {
                    continue;
                }
                $y0 = $y($base);
                $y1 = $y($base + $v);
                // The surface gap: every segment above the first starts 2 px higher up.
                if ($base > 0) {
                    $y0 = round($y0 - self::GAP, 2);
                }
                $segments[] = ['series' => $id, 'value' => $v, 'd' => self::segment($x, $w, $y1, $y0, $k === $last)];
                $base += $v;
            }
            // The band behind the column is its hover target, taller and wider than the column.
            $bars[] = ['index' => $i, 'x' => $x, 'w' => round($w, 2), 'bx' => round($plot['x'] + $band * $i, 2), 'bw' => round($band, 2), 'total' => $totals[$i], 'segments' => $segments];
        }

        $ticks = [];
        $step = $top / 4;
        for ($t = 0; $t <= 4; ++$t) {
            $ticks[] = ['y' => $y($step * $t), 'label' => self::compact($step * $t)];
        }

        $every = max(1, (int) ceil($n / self::LABELS_MAX));
        $xLabels = [];
        for ($i = $n - 1; $i >= 0; $i -= $every) {
            $xLabels[] = ['x' => round($plot['x'] + $band * $i + $band / 2, 2), 'text' => $labels[$i]];
        }

        return [
            'width' => self::WIDTH,
            'height' => self::HEIGHT,
            'plot' => $plot,
            'bars' => $bars,
            'ticks' => $ticks,
            'limit' => $showLimit ? ['y' => $y($line), 'value' => $line] : null,
            'xLabels' => array_reverse($xLabels),
            'max' => $max,
        ];
    }

    /**
     * A clean top for the scale: four clean steps, a step being 1, 2, 2.5 or 5
     * times a power of ten and at least 1, so every tick is a round number.
     */
    public static function niceTop(float $v): float
    {
        $step = max(1.0, $v / 4);
        $pow = 10 ** floor(log10($step));
        foreach ([1, 2, 2.5, 5, 10] as $m) {
            if ($m * $pow >= $step) {
                return 4 * $m * $pow;
            }
        }

        return 40 * $pow;
    }

    /** A tick or value, compact from 1,000: 0, 250, 1.5K, 10K, 300K. */
    public static function compact(float $v): string
    {
        if ($v >= 1000) {
            $k = $v / 1000;

            return (self::whole($k) ? (string) (int) $k : rtrim(rtrim(number_format($k, 1, '.', ''), '0'), '.')).'K';
        }

        return self::whole($v) ? (string) (int) $v : number_format($v, 1, '.', '');
    }

    private static function whole(float $v): bool
    {
        return abs($v - round($v)) < 1e-9;
    }

    /** One segment, square at the bottom; the top one has rounded top corners. */
    private static function segment(float $x, float $w, float $top, float $bottom, bool $rounded): string
    {
        $h = $bottom - $top;
        $r = $rounded ? min(self::RADIUS, $h, $w / 2) : 0.0;
        $f = static fn (float $v): string => rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
        if ($r <= 0) {
            return sprintf('M%s %sH%sV%sH%sZ', $f($x), $f($bottom), $f($x + $w), $f($top), $f($x));
        }

        return sprintf(
            'M%s %sV%sQ%s %s %s %sH%sQ%s %s %s %sV%sZ',
            $f($x), $f($bottom), $f($top + $r),
            $f($x), $f($top), $f($x + $r), $f($top),
            $f($x + $w - $r),
            $f($x + $w), $f($top), $f($x + $w), $f($top + $r),
            $f($bottom),
        );
    }
}
