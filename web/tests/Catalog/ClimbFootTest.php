<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\ClimbFoot;
use App\Catalog\Entity\Region;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20260930095000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A climb's point is the foot of its line (owner 2026-09-30: "All climb
 * points must be the start point"; docs/specs/edit-items/N-climbs.md).
 *
 * The database keeps it on every write (the item_climb_at_foot trigger), so a
 * writer that stores a climb's line with any other point, or none of its own,
 * still stores the foot. Raw SQL here on purpose: it is the path a tool
 * writing through psql takes, with no PHP in between.
 */
final class ClimbFootTest extends KernelTestCase
{
    private function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    /** @param array<string, mixed> $attributes */
    private function insert(string $letter, float $lng, float $lat, array $attributes, ?int $regionId = null): int
    {
        return (int) $this->db()->fetchOne(
            "INSERT INTO item (letter, name, geom, country_code, region_id, state, source, source_ref, attributes, created_at, updated_at)
             VALUES (:letter, 'Foot test', ST_SetSRID(ST_MakePoint(:lng, :lat), 4326), 'BE', :rid, 'unverified', 'manual',
                     :ref, :attrs, NOW(), NOW())
             RETURNING id",
            [
                'letter' => $letter, 'lng' => $lng, 'lat' => $lat, 'rid' => $regionId,
                'ref' => 'foot-test-'.bin2hex(random_bytes(4)),
                'attrs' => json_encode($attributes, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION),
            ],
        );
    }

    /** @return array{0: float, 1: float, 2: int|null} [lat, lng, region_id] */
    private function point(int $id): array
    {
        /** @var array{lat: string|float, lng: string|float, region_id: int|string|null} $row */
        $row = $this->db()->fetchAssociative('SELECT ST_Y(geom) AS lat, ST_X(geom) AS lng, region_id FROM item WHERE id = :id', ['id' => $id]);

        return [(float) $row['lat'], (float) $row['lng'], null === $row['region_id'] ? null : (int) $row['region_id']];
    }

    /** A line foot to summit, [lat, lng]. */
    private const array LINE = [[50.40, 5.80], [50.41, 5.81], [50.42, 5.82]];

    public function testAClimbWrittenWithItsSummitIsStoredAtItsFoot(): void
    {
        $id = $this->insert('N', 5.82, 50.42, ['route' => self::LINE]);

        self::assertEqualsWithDelta([50.40, 5.80], \array_slice($this->point($id), 0, 2), 1e-9);
    }

    public function testARedrawnLineTakesThePointToTheNewFoot(): void
    {
        $id = $this->insert('N', 5.80, 50.40, ['route' => self::LINE]);

        $this->db()->executeStatement(
            "UPDATE item SET attributes = jsonb_set(attributes, '{route}', :r::jsonb) WHERE id = :id",
            ['r' => '[[50.39, 5.79], [50.42, 5.82]]', 'id' => $id],
        );

        self::assertEqualsWithDelta([50.39, 5.79], \array_slice($this->point($id), 0, 2), 1e-9);
    }

    public function testMovingALinedClimbsPointElsewhereKeepsItAtTheFoot(): void
    {
        $id = $this->insert('N', 5.80, 50.40, ['route' => self::LINE]);

        $this->db()->executeStatement('UPDATE item SET geom = ST_SetSRID(ST_MakePoint(6.0, 51.0), 4326) WHERE id = :id', ['id' => $id]);

        self::assertEqualsWithDelta([50.40, 5.80], \array_slice($this->point($id), 0, 2), 1e-9);
    }

    public function testAClimbWithNoUsableLineKeepsItsPoint(): void
    {
        foreach ([[], ['route' => [[50.40, 5.80]]], ['route' => [['50.40', '5.80'], [50.42, 5.82]]], ['route' => 'none']] as $attributes) {
            $id = $this->insert('N', 5.82, 50.42, $attributes);
            self::assertEqualsWithDelta([50.42, 5.82], \array_slice($this->point($id), 0, 2), 1e-9, json_encode($attributes, \JSON_THROW_ON_ERROR));
        }
    }

    public function testOnlyClimbsAreMoved(): void
    {
        $id = $this->insert('P', 5.82, 50.42, ['route' => self::LINE]);

        self::assertEqualsWithDelta([50.42, 5.82], \array_slice($this->point($id), 0, 2), 1e-9);
    }

    /**
     * The migration's backfill: a climb stored before the rule moves to its
     * foot and is filed in the region its foot is in, with that region's
     * country; a climb with no line keeps its point and its region.
     */
    public function testTheMigrationMovesAnExistingClimbToItsFootAndItsFootsRegion(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $summitLand = (new Region())->setSlug('summit-land')->setName('Summit land')->setCountryCode('BE')->setAreaKm2(10.0)
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[5.815,50.415],[5.83,50.415],[5.83,50.43],[5.815,50.43],[5.815,50.415]]]]}');
        $footLand = (new Region())->setSlug('foot-land')->setName('Foot land')->setCountryCode('IT')->setAreaKm2(10.0)
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[5.79,50.39],[5.805,50.39],[5.805,50.405],[5.79,50.405],[5.79,50.39]]]]}');
        $em->persist($summitLand);
        $em->persist($footLand);
        $em->flush();

        // A climb as the catalog held it before the rule: pinned at its summit.
        $this->db()->executeStatement('ALTER TABLE item DISABLE TRIGGER item_climb_at_foot_ins');
        $lined = $this->insert('N', 5.82, 50.42, ['route' => self::LINE], $summitLand->getId());
        $unlined = $this->insert('N', 5.82, 50.42, [], $summitLand->getId());
        $this->db()->executeStatement('ALTER TABLE item ENABLE TRIGGER item_climb_at_foot_ins');
        self::assertEqualsWithDelta([50.42, 5.82], \array_slice($this->point($lined), 0, 2), 1e-9);

        require_once \dirname(__DIR__, 2).'/migrations/Version20260930095000.php';
        $migration = new Version20260930095000($this->db(), new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->db()->executeStatement($query->getStatement());
        }

        [$lat, $lng, $region] = $this->point($lined);
        self::assertEqualsWithDelta([50.40, 5.80], [$lat, $lng], 1e-9);
        self::assertSame($footLand->getId(), $region);
        self::assertSame('IT', $this->db()->fetchOne('SELECT country_code FROM item WHERE id = :id', ['id' => $lined]));
        self::assertSame([50.42, 5.82, $summitLand->getId()], $this->point($unlined));
        self::assertSame('BE', $this->db()->fetchOne('SELECT country_code FROM item WHERE id = :id', ['id' => $unlined]));
    }

    /**
     * A climb's country is its region's country (owner 2026-09-30), whoever
     * files it: a climb whose foot is over the border takes the neighbour's
     * code. With no region it keeps the country it was given, and a place
     * that is not a climb is never touched.
     */
    public function testAClimbTakesTheCountryOfTheRegionItIsFiledIn(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $aosta = (new Region())->setSlug('foot-country')->setName('Foot country')->setCountryCode('IT')->setAreaKm2(10.0)
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[5.79,50.39],[5.805,50.39],[5.805,50.405],[5.79,50.405],[5.79,50.39]]]]}');
        $em->persist($aosta);
        $em->flush();
        $country = fn (int $id): mixed => $this->db()->fetchOne('SELECT country_code FROM item WHERE id = :id', ['id' => $id]);

        $filed = $this->insert('N', 5.80, 50.40, ['route' => self::LINE], $aosta->getId());
        self::assertSame('IT', $country($filed), 'inserted with a region');

        $later = $this->insert('N', 5.80, 50.40, ['route' => self::LINE]);
        self::assertSame('BE', $country($later), 'no region, the given country stays');
        $this->db()->executeStatement('UPDATE item SET region_id = :r WHERE id = :id', ['r' => $aosta->getId(), 'id' => $later]);
        self::assertSame('IT', $country($later), 'filed in a region later');

        $place = $this->insert('P', 5.80, 50.40, [], $aosta->getId());
        self::assertSame('BE', $country($place), 'only climbs follow their region');
    }

    public function testThePhpRuleReadsTheSameFootAsTheDatabase(): void
    {
        self::assertSame([50.40, 5.80], ClimbFoot::of(self::LINE));
        self::assertSame([50.0, 5.0], ClimbFoot::of([[50, 5], [51, 6]]));
        self::assertNull(ClimbFoot::of(null));
        self::assertNull(ClimbFoot::of('none'));
        self::assertNull(ClimbFoot::of([[50.40, 5.80]]));
        self::assertNull(ClimbFoot::of([['50.40', '5.80'], [50.42, 5.82]]));
        self::assertNull(ClimbFoot::of(['a' => [50.40, 5.80], 'b' => [50.42, 5.82]]));
    }
}
