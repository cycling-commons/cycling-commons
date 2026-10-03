<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Command;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:vote:freeze`, the daily timer that stores season lists whose ballot
 * has closed, so a result is fixed when its season starts and not when
 * somebody first reads it.
 *
 * @see docs/specs/route-domain.md §8d
 */
final class VoteFreezeCommandTest extends KernelTestCase
{
    private const int REGION = 900002;

    private Connection $db;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = static::getContainer()->get(Connection::class);
    }

    #[\Override]
    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    private function vote(int $user, int $subject, string $start, string $category = 'climbs', ?string $bike = null, string $season = 'spring', int $slot = 1, bool $submitted = true): void
    {
        $this->db->insert('season_vote', [
            'user_id' => $user, 'region_id' => self::REGION, 'category' => $category, 'subject_id' => $subject,
            'bike_type' => $bike, 'season' => $season, 'round_start' => $start, 'slot' => $slot, 'created_at' => $start.' 10:00:00',
            'submitted_at' => $submitted ? $start.' 10:05:00' : null,
        ]);
    }

    private function run_(string $now): CommandTester
    {
        Clock::set(new MockClock(new \DateTimeImmutable($now)));
        $tester = new CommandTester((new Application(self::$kernel))->find('app:vote:freeze'));
        $tester->execute([]);

        return $tester;
    }

    /** @return array<string, int> bike column => rows stored for that round */
    private function stored(string $start): array
    {
        $out = [];
        foreach ($this->db->fetchAllAssociative(
            'SELECT bike_type, category, COUNT(*) AS n FROM season_result WHERE region_id = ? AND round_start = ? GROUP BY bike_type, category ORDER BY category, bike_type',
            [self::REGION, $start],
        ) as $r) {
            $out[$r['category'].'/'.$r['bike_type']] = (int) $r['n'];
        }

        return $out;
    }

    public function testItStoresEveryListOfTheRoundThatClosed(): void
    {
        $this->vote(1, 1001, '2027-03-01');
        $this->vote(2, 1002, '2027-03-01');
        $this->vote(1, 2001, '2027-03-01', 'quality-rides', 'Gravel');
        $this->vote(2, 2001, '2027-03-01', 'quality-rides', 'Road');
        $this->vote(3, 2002, '2027-03-01', 'quality-rides', 'Road');
        // In June riders vote for autumn: that ballot is open and not touched.
        $this->vote(1, 1001, '2027-09-01', season: 'autumn');

        $tester = $this->run_('2027-06-01T01:30:00+00:00');

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Stored 4 closed season list(s)', $tester->getDisplay());
        self::assertSame([
            'climbs/' => 2,
            'quality-rides/' => 2,
            'quality-rides/Gravel' => 1,
            'quality-rides/Road' => 2,
        ], $this->stored('2027-03-01'));
        self::assertSame([], $this->stored('2027-09-01'));
    }

    /** Summer's ballot closes at 00:00 on 1 June; in the hour after, its list waits. */
    public function testAListInItsGraceHourWaitsForTheNextRun(): void
    {
        $this->vote(1, 1001, '2027-06-01', season: 'summer');

        $tester = $this->run_('2027-06-01T00:59:00+00:00');

        $tester->assertCommandIsSuccessful();
        self::assertSame([], $this->stored('2027-06-01'));
    }

    public function testASecondRunStoresNothingAndChangesNothing(): void
    {
        $this->vote(1, 1001, '2027-03-01');
        $this->vote(2, 1001, '2027-03-01');
        $this->run_('2027-06-02T03:00:00+00:00');
        $before = $this->db->fetchAllAssociative('SELECT * FROM season_result WHERE region_id = ? ORDER BY id', [self::REGION]);

        $tester = $this->run_('2027-06-03T03:00:00+00:00');

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Stored 0 closed season list(s)', $tester->getDisplay());
        self::assertSame($before, $this->db->fetchAllAssociative('SELECT * FROM season_result WHERE region_id = ? ORDER BY id', [self::REGION]));
    }
}
