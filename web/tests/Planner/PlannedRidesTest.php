<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Planner;

use App\Catalog\BikeType;
use App\Entity\User;
use App\Planner\PlannedRides;
use App\Planner\RideInput;
use App\Planner\RideRefused;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The saved rides behind the planner (docs/specs/route-planner.md section
 * 6.1 and 8): every read and write is for one rider, the line is measured
 * where it is stored, and a rider holds at most a hundred rides.
 */
final class PlannedRidesTest extends KernelTestCase
{
    use RideFixtures;

    private EntityManagerInterface $em;
    private Connection $db;
    private PlannedRides $rides;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->db = static::getContainer()->get(Connection::class);
        $this->rides = static::getContainer()->get(PlannedRides::class);
    }

    private function user(string $tag): User
    {
        $user = (new User())->setEmail("rides-{$tag}@test.test");
        $user->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** @param array<string, mixed> $overrides */
    private function input(array $overrides = []): RideInput
    {
        $input = RideInput::parse(self::body($this->db, $overrides));
        self::assertNotNull($input);

        return $input;
    }

    public function testAnotherRidersRideIsAMissingRide(): void
    {
        $a = $this->user('scope-a');
        $b = $this->user('scope-b');
        $id = self::seedRide($this->db, (int) $a->getId());

        self::assertNull($this->rides->find($b, $id));
        self::assertSame([], $this->rides->listFor($b));
        self::assertSame(0, $this->rides->count($b));
        self::assertFalse($this->rides->delete($b, $id));
        try {
            $this->rides->save($b, $this->input(['id' => $id, 'name' => 'Taken over']));
            self::fail('a save under another rider\'s id went through');
        } catch (RideRefused $e) {
            self::assertSame(RideRefused::NOT_FOUND, $e->reason);
        }

        self::assertSame('Ride of A', $this->db->fetchOne('SELECT name FROM planned_ride WHERE id = ?', [$id]));
        self::assertNotNull($this->rides->find($a, $id));
        self::assertSame(1, $this->rides->count($a));
    }

    public function testSaveInsertsThenUpdatesInPlace(): void
    {
        $rider = $this->user('save');

        $ride = $this->rides->save($rider, $this->input());
        $id = $ride->getId();
        self::assertNotNull($id);
        self::assertSame('Sunday loop', $ride->getName());
        self::assertSame(BikeType::Road, $ride->getBike());
        self::assertSame(1, $this->rides->count($rider));

        $again = $this->rides->save($rider, $this->input(['id' => $id, 'name' => 'Renamed', 'bike' => 'Gravel', 'ascentM' => 300]));
        self::assertSame($id, $again->getId());
        self::assertSame(1, $this->rides->count($rider));
        $this->em->clear();
        $stored = $this->rides->find($rider, $id);
        self::assertNotNull($stored);
        self::assertSame('Renamed', $stored->getName());
        self::assertSame(BikeType::Gravel, $stored->getBike());
        self::assertSame(300, $stored->getAscentM());
        self::assertSame('Fontaine', $stored->getStops()[1]['name']);
        $line = json_decode($stored->getLine(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('LineString', $line['type']);
        self::assertCount(3, $line['coordinates']);
    }

    public function testTheClaimedDistanceMustBeWithinFivePercentOfTheLine(): void
    {
        $rider = $this->user('length');
        $length = self::lengthOf($this->db, self::line());

        // Four percent off passes, six percent off does not.
        $this->rides->save($rider, $this->input(['distanceM' => (int) round($length * 1.04)]));
        self::assertSame(1, $this->rides->count($rider));

        $this->expectException(RideRefused::class);
        $this->expectExceptionMessage(RideRefused::LENGTH_MISMATCH);
        $this->rides->save($rider, $this->input(['distanceM' => (int) round($length * 0.94)]));
    }

    public function testALineLongerThanTheRouteDomainCapIsRefused(): void
    {
        $rider = $this->user('long');
        // Four degrees of latitude: about 445 km.
        $line = ['type' => 'LineString', 'coordinates' => [[5.0, 50.0], [5.0, 54.0]]];
        $length = self::lengthOf($this->db, $line);
        self::assertGreaterThan(PlannedRides::MAX_LENGTH_M, $length);

        $this->expectException(RideRefused::class);
        $this->expectExceptionMessage(RideRefused::LENGTH_RANGE);
        $this->rides->save($rider, $this->input(['line' => $line, 'distanceM' => (int) round($length)]));
    }

    public function testTheHundredAndFirstRideIsRefusedAndAnUpdateIsNot(): void
    {
        $rider = $this->user('cap');
        self::seedRides($this->db, (int) $rider->getId(), PlannedRides::MAX_RIDES);
        $one = (int) $this->db->fetchOne('SELECT MIN(id) FROM planned_ride WHERE user_id = ?', [(int) $rider->getId()]);

        $this->rides->save($rider, $this->input(['id' => $one, 'name' => 'Still mine']));
        self::assertSame(PlannedRides::MAX_RIDES, $this->rides->count($rider));

        $this->expectException(RideRefused::class);
        $this->expectExceptionMessage(RideRefused::TOO_MANY_RIDES);
        $this->rides->save($rider, $this->input());
    }

    public function testTheListIsNewestUpdatedFirst(): void
    {
        $rider = $this->user('list');
        $older = self::seedRide($this->db, (int) $rider->getId(), 'Older', "NOW() - INTERVAL '2 days'");
        $newer = self::seedRide($this->db, (int) $rider->getId(), 'Newer', "NOW() - INTERVAL '1 day'");

        self::assertSame([$newer, $older], array_map(static fn ($r): ?int => $r->getId(), $this->rides->listFor($rider)));
    }

    public function testDeleteRemovesTheRidersRide(): void
    {
        $rider = $this->user('delete');
        $id = self::seedRide($this->db, (int) $rider->getId());

        self::assertTrue($this->rides->delete($rider, $id));
        self::assertFalse($this->rides->delete($rider, $id));
        self::assertNull($this->rides->find($rider, $id));
        self::assertSame(0, $this->rides->count($rider));
    }
}
