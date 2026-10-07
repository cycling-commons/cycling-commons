<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Traffic;

use App\Traffic\TrafficStore;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Encrypted traffic storage (docs/specs/traffic-measurements.md §4.3): running
 * totals that add up, and rows that say nothing readable.
 */
final class TrafficStoreTest extends KernelTestCase
{
    private TrafficStore $store;
    private Connection $db;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->store = static::getContainer()->get(TrafficStore::class);
        $this->db = static::getContainer()->get(Connection::class);
    }

    /** @return array<string, mixed> */
    private static function line(array $over = []): array
    {
        return $over + [
            'way' => 4521877, 'dir' => 'f', 'label' => 'r', 'slot' => 73, 'dayType' => 'workday',
            'season' => 'autumn', 'quarter' => '2026-Q4', 'day' => 20366,
            'distanceM' => 3210, 'timeS' => 421, 'passes' => 7, 'avgSpeedKmh' => 27.4,
            'carSpeedBins' => [0, 0, 0, 0, 0, 1, 3, 2, 1, 0, 0, 0, 0, 0, 0, 0],
        ];
    }

    public function testTwoLinesOfOneKeyAddUp(): void
    {
        $this->store->addToCell(self::line());
        $this->store->addToCell(self::line(['distanceM' => 1000, 'timeS' => 200, 'passes' => 1, 'carSpeedBins' => null]));

        $cells = iterator_to_array($this->store->cells(), false);
        self::assertCount(1, $cells);
        $c = $cells[0];
        self::assertSame(4521877, $c['way']);
        self::assertSame(4210, $c['distanceM']);
        self::assertSame(621, $c['timeS']);
        self::assertSame(8, $c['passes']);
        self::assertSame(7, $c['speedPasses'], 'only the first line measured car speeds');
        self::assertSame(2, $c['contributions']);
        self::assertSame(3, $c['bins'][6]);
    }

    public function testAnotherSlotIsAnotherCell(): void
    {
        $this->store->addToCell(self::line());
        $this->store->addToCell(self::line(['slot' => 74]));

        self::assertCount(2, iterator_to_array($this->store->cells(), false));
    }

    public function testTheRowsHoldNoReadableRoadTimeOrCount(): void
    {
        $this->store->addToCell(self::line());
        $this->store->addToRider(42, self::line());

        foreach (['traffic_cell', 'traffic_rider'] as $table) {
            foreach ($this->db->fetchAllAssociative("SELECT * FROM {$table}") as $row) {
                $bytes = implode('', array_map(static fn ($v) => \is_resource($v) ? stream_get_contents($v) : (string) $v, $row));
                foreach (['4521877', '2026-Q4', 'workday', 'autumn', '3210', pack('N', 4521877), pack('V', 4521877)] as $needle) {
                    self::assertStringNotContainsString($needle, $bytes, "{$table} leaks {$needle}");
                }
            }
        }
    }

    public function testARiderRowKeepsDistanceAndDaysPerBucket(): void
    {
        $this->store->addToRider(42, self::line());
        $this->store->addToRider(42, self::line(['day' => 20367, 'distanceM' => 500]));
        $this->store->addToRider(43, self::line());

        $riders = $this->store->riders();
        self::assertCount(2, $riders);
        $mine = array_values(array_filter($riders, static fn ($r) => 3710 === array_sum(array_column($r['buckets'], 'd'))));
        self::assertCount(1, $mine);
        self::assertSame(4521877, $mine[0]['way']);
        self::assertSame([20366, 20367], array_values($mine[0]['buckets'])[0]['days']);
    }

    public function testACodeIsClaimedOnce(): void
    {
        $a = str_repeat('a', 64);
        $b = str_repeat('b', 64);

        self::assertSame([$a, $b], $this->store->claimCodes([$b, $a, $a]));
        self::assertSame([], $this->store->claimCodes([$a]), 'a second claim gets nothing');
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM traffic_seen'));
    }

    public function testDeletingARiderRemovesOnlyTheirRows(): void
    {
        $this->store->addToRider(42, self::line());
        $this->store->addToRider(42, self::line(['way' => 99]));
        $this->store->addToRider(43, self::line());

        self::assertSame(2, $this->store->deleteRidersOf(42));
        self::assertCount(1, $this->store->riders());
    }

    public function testNoColumnLinksTheRowsOfOneRoad(): void
    {
        foreach (['traffic_cell' => ['bucket_key', 'payload'], 'traffic_rider' => ['payload', 'rider_key']] as $table => $columns) {
            $got = $this->db->fetchFirstColumn('SELECT column_name FROM information_schema.columns WHERE table_name = ? ORDER BY column_name', [$table]);
            self::assertSame($columns, $got, "{$table} holds only its key and the sealed payload");
        }
    }

    public function testAPayloadMovedToAnotherRowNoLongerOpens(): void
    {
        $this->store->addToCell(self::line());
        $this->store->addToCell(self::line(['slot' => 74]));
        $rows = $this->db->fetchAllAssociative('SELECT bucket_key, payload FROM traffic_cell');
        $blob = \is_resource($rows[0]['payload']) ? stream_get_contents($rows[0]['payload']) : $rows[0]['payload'];
        $this->db->executeStatement('UPDATE traffic_cell SET payload = ? WHERE bucket_key = ?', [$blob, $rows[1]['bucket_key']],
            [\Doctrine\DBAL\ParameterType::BINARY, \Doctrine\DBAL\ParameterType::BINARY]);

        $this->expectException(\RuntimeException::class);
        iterator_to_array($this->store->cells(), false);
    }

    public function testOnlyPassesWithAMeasuredSpeedCountAsMeasured(): void
    {
        $bins = array_fill(0, 16, 0);
        $bins[6] = 3;
        $this->store->addToCell(self::line(['passes' => 7, 'carSpeedBins' => $bins]));

        self::assertSame(3, iterator_to_array($this->store->cells(), false)[0]['speedPasses']);
    }

    public function testNearbyCarsAddUpApartFromPassingOnes(): void
    {
        $path = ['label' => 'p', 'passes' => 0, 'nearby' => 4];
        $this->store->addToCell(self::line($path));
        $this->store->addToCell(self::line($path + ['distanceM' => 1000, 'timeS' => 200, 'carSpeedBins' => null]));

        $cells = iterator_to_array($this->store->cells(), false);
        self::assertCount(1, $cells);
        self::assertSame(8, $cells[0]['nearby']);
        self::assertSame(0, $cells[0]['passes']);
    }

    public function testACellKeepsItsRoadsRegion(): void
    {
        $this->store->addToCell(self::line(['region' => 12]));
        $this->store->addToCell(self::line(['distanceM' => 500, 'timeS' => 90, 'carSpeedBins' => null]));

        $cells = iterator_to_array($this->store->cells(), false);
        self::assertSame(12, $cells[0]['region'], 'a later line without one does not erase it');
    }
}
