<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Vote;

/**
 * How long a ballot stays open, said the way the time left calls for (owner
 * 2026-10-03): days while there are more than two, then hours, then hours and
 * minutes in the last day, and a clock to the second in the last hour. The
 * page renders this first value; `assets/js/countdown.js` keeps it moving with
 * the same steps.
 *
 * @api
 */
final class Countdown
{
    /**
     * @return array{key: string, params: array<string, int|string>} the catalogue key and its parameters
     */
    public static function of(\DateTimeImmutable $now, \DateTimeImmutable $deadline): array
    {
        $s = $deadline->getTimestamp() - $now->getTimestamp();
        if ($s <= 0) {
            return ['key' => 'vote.cd_closed', 'params' => []];
        }
        if ($s < 3600) {
            return ['key' => 'vote.cd_hms', 'params' => ['%time%' => sprintf('00:%02d:%02d', intdiv($s, 60), $s % 60)]];
        }
        if ($s < 86400) {
            return ['key' => 'vote.cd_hm', 'params' => ['%h%' => intdiv($s, 3600), '%m%' => sprintf('%02d', intdiv($s % 3600, 60))]];
        }
        if ($s < 172800) {
            return ['key' => 'vote.cd_hours', 'params' => ['%count%' => intdiv($s, 3600)]];
        }

        return ['key' => 'vote.cd_days', 'params' => ['%count%' => intdiv($s, 86400)]];
    }
}
