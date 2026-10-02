<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Vote;

use App\Catalog\Season;

/**
 * One riding season of one year in one hemisphere: the round a ballot list
 * belongs to.
 *
 * Meteorological seasons, three whole months each, starting on the 1st at
 * 00:00 UTC. A round's year is the year of its first month, so northern winter
 * 2026 runs from December 2026 to February 2027.
 *
 * Every window here is computed from a round's first day, never by taking
 * months off the clock: "now minus three months" on 31 May is 3 March.
 *
 * @see docs/specs/route-domain.md §8c, §8d
 *
 * @api
 */
final readonly class Round
{
    /** @var array<int, Season> first month => season */
    private const array NORTH = [3 => Season::Spring, 6 => Season::Summer, 9 => Season::Autumn, 12 => Season::Winter];

    /** @var array<int, Season> first month => season */
    private const array SOUTH = [3 => Season::Autumn, 6 => Season::Winter, 9 => Season::Spring, 12 => Season::Summer];

    private function __construct(
        public Season $season,
        public int $year,
        public Hemisphere $hemisphere,
    ) {
    }

    public static function of(Season $season, int $year, Hemisphere $hemisphere): self
    {
        return new self($season, $year, $hemisphere);
    }

    public static function containing(\DateTimeImmutable $at, Hemisphere $hemisphere): self
    {
        $utc = $at->setTimezone(new \DateTimeZone('UTC'));
        $month = (int) $utc->format('n');
        $year = (int) $utc->format('Y');
        $first = $month - $month % 3;
        if (0 === $first) {
            $first = 12;
            --$year;
        }

        return new self(self::map($hemisphere)[$first], $year, $hemisphere);
    }

    /** The most recent round of this season that has opened by `$now`. */
    public static function latestStarted(Season $season, Hemisphere $hemisphere, \DateTimeImmutable $now): self
    {
        $year = (int) $now->setTimezone(new \DateTimeZone('UTC'))->format('Y');
        $round = new self($season, $year, $hemisphere);

        return $round->opensAt() <= $now ? $round : new self($season, $year - 1, $hemisphere);
    }

    /**
     * The round a stored `season` and `round_start` name. The hemisphere
     * follows from the two: a December start is northern winter or southern
     * summer.
     *
     * @throws \InvalidArgumentException when no round of that season starts on that day
     */
    public static function fromStored(string $season, string $roundStart): self
    {
        $s = Season::from($season);
        $start = new \DateTimeImmutable($roundStart.'T00:00:00+00:00');
        foreach (Hemisphere::cases() as $hemisphere) {
            $round = new self($s, (int) $start->format('Y'), $hemisphere);
            if ($round->startDate() === $start->format('Y-m-d')) {
                return $round;
            }
        }

        throw new \InvalidArgumentException(sprintf('No %s round starts on %s.', $season, $roundStart));
    }

    public function firstMonth(): int
    {
        return (int) array_search($this->season, self::map($this->hemisphere), true);
    }

    public function opensAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(sprintf('%04d-%02d-01T00:00:00+00:00', $this->year, $this->firstMonth()));
    }

    public function closesAt(): \DateTimeImmutable
    {
        return $this->opensAt()->modify('+3 months');
    }

    public function lastDay(): \DateTimeImmutable
    {
        return $this->closesAt()->modify('-1 day');
    }

    public function isOpenAt(\DateTimeImmutable $at): bool
    {
        return $this->opensAt() <= $at && $at < $this->closesAt();
    }

    public function hasClosedBy(\DateTimeImmutable $at): bool
    {
        return $this->closesAt() <= $at;
    }

    /** The same season a year earlier: the round the handicap looks at. */
    public function yearBefore(): self
    {
        return new self($this->season, $this->year - 1, $this->hemisphere);
    }

    /** `season_vote.round_start`. */
    public function startDate(): string
    {
        return $this->opensAt()->format('Y-m-d');
    }

    /** "2027", or "2026-27" for a round that starts in December. */
    public function yearLabel(): string
    {
        return 12 === $this->firstMonth()
            ? sprintf('%d-%02d', $this->year, ($this->year + 1) % 100)
            : (string) $this->year;
    }

    /** @return array<int, Season> */
    private static function map(Hemisphere $hemisphere): array
    {
        return Hemisphere::North === $hemisphere ? self::NORTH : self::SOUTH;
    }
}
