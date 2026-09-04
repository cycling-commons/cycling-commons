<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Provider;

use App\Provider\Entity\DataProvider;
use App\Provider\ProviderHarvest;
use App\Tests\Coverage\CoverageSchema;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Match, then attach or insert (data-provider-hierarchy.md §5).
 */
final class ProviderHarvestTest extends KernelTestCase
{
    // coverage_poi is pipeline-owned DDL, excluded from Doctrine's schema
    // filter, so no migration creates it. Built inside the test transaction.
    use CoverageSchema;

    private const float LAT = 50.111111;
    private const float LNG = 4.222222;

    protected function setUp(): void
    {
        self::bootKernel();
        self::ensureCoverageSchema($this->db());
        $this->db()->executeStatement("DELETE FROM item WHERE source_ref LIKE 'harvest-test:%'");
        $this->db()->executeStatement("DELETE FROM coverage_poi WHERE ref LIKE 'node/999%'");
    }

    /**
     * Found within the radius: the item points AT the node, and the existing
     * suppression hides the raw pin rather than drawing both.
     */
    public function testAFeatureNearAnOsmNodeAttachesToIt(): void
    {
        $provider = $this->provider(50);
        $this->coveragePoi('node/99901', self::LAT, self::LNG);

        $counts = $this->harvest()->apply($provider, [$this->feature('harvest-test:attach', self::LAT, self::LNG)]);

        self::assertSame(1, $counts['inserted']);
        self::assertSame(1, $counts['attached']);
        self::assertSame('node/99901', $this->db()->fetchOne(
            "SELECT osm_ref FROM item WHERE source_ref = 'harvest-test:attach'",
        ));
    }

    /**
     * Nothing within the radius: osm_ref stays NULL and osm_checked_at is set,
     * which is "we looked and there is nothing" rather than "nobody looked".
     */
    public function testAFeatureWithNoCounterpartInsertsAsCheckedAndUnmatched(): void
    {
        $provider = $this->provider(50);

        $counts = $this->harvest()->apply($provider, [$this->feature('harvest-test:insert', self::LAT, self::LNG)]);

        self::assertSame(1, $counts['inserted']);
        self::assertSame(0, $counts['attached']);
        $row = $this->db()->fetchAssociative(
            "SELECT osm_ref, osm_checked_at FROM item WHERE source_ref = 'harvest-test:insert'",
        );
        self::assertIsArray($row);
        self::assertNull($row['osm_ref']);
        self::assertNotNull($row['osm_checked_at'], 'a checked answer is not a gap');
    }

    /** The radius is the provider's judgement, so it decides the outcome. */
    public function testTheProvidersOwnRadiusDecidesWhetherItMatches(): void
    {
        $this->coveragePoi('node/99902', self::LAT, self::LNG);
        // ~78 m east of the node.
        $far = self::LNG + 0.0011;

        $tight = $this->harvest()->apply($this->provider(25), [$this->feature('harvest-test:tight', self::LAT, $far)]);
        self::assertSame(0, $tight['attached'], '25 m under-matches and creates a second pin');

        $this->db()->executeStatement("DELETE FROM item WHERE source_ref = 'harvest-test:tight'");

        $loose = $this->harvest()->apply($this->provider(250), [$this->feature('harvest-test:loose', self::LAT, $far)]);
        self::assertSame(1, $loose['attached'], '250 m matches the same pair');
    }

    /**
     * Rule 1 of the hierarchy: a curator cannot give a provider a rank that
     * outranks a rider's own contribution, and neither can a harvest.
     */
    public function testAPlaceARiderAlreadyFiledIsLeftAlone(): void
    {
        $provider = $this->provider(50);
        $this->db()->executeStatement(
            "INSERT INTO item (letter, name, geom, country_code, state, source, source_ref, attributes, created_at, updated_at)
             VALUES ('B', 'Fontein', ST_SetSRID(ST_MakePoint(:lng, :lat), 4326), 'BE', 'verified', 'user', 'harvest-test:rider', '{}', NOW(), NOW())",
            ['lat' => self::LAT, 'lng' => self::LNG],
        );

        $counts = $this->harvest()->apply($provider, [$this->feature('harvest-test:overlaps-rider', self::LAT, self::LNG)]);

        self::assertSame(1, $counts['skipped_rider']);
        self::assertSame(0, $counts['inserted']);
        self::assertFalse($this->db()->fetchOne(
            "SELECT id FROM item WHERE source_ref = 'harvest-test:overlaps-rider'",
        ));
    }

    /** A re-run updates the facts and never duplicates the row. */
    public function testARerunUpdatesRatherThanDuplicating(): void
    {
        $provider = $this->provider(50);
        $this->harvest()->apply($provider, [$this->feature('harvest-test:again', self::LAT, self::LNG)]);

        $moved = $this->feature('harvest-test:again', self::LAT + 0.0001, self::LNG);
        $moved['name'] = 'Renamed upstream';
        $counts = $this->harvest()->apply($provider, [$moved]);

        self::assertSame(1, $counts['updated']);
        self::assertSame(0, $counts['inserted']);
        self::assertSame(1, (int) $this->db()->fetchOne(
            "SELECT COUNT(*) FROM item WHERE source_ref = 'harvest-test:again'",
        ));
        self::assertSame('Renamed upstream', $this->db()->fetchOne(
            "SELECT name FROM item WHERE source_ref = 'harvest-test:again'",
        ));
    }

    /**
     * "The publisher dropped it" and "the publisher's export broke" look
     * identical from here, so a vanished feature is counted, never deleted.
     */
    public function testAVanishedFeatureIsCountedAndNotDeleted(): void
    {
        $provider = $this->provider(50);
        $this->harvest()->apply($provider, [
            $this->feature('harvest-test:stays', self::LAT, self::LNG),
            $this->feature('harvest-test:vanishes', self::LAT + 0.01, self::LNG),
        ]);

        $counts = $this->harvest()->apply($provider, [$this->feature('harvest-test:stays', self::LAT, self::LNG)]);

        self::assertSame(1, $counts['stale']);
        self::assertNotFalse($this->db()->fetchOne(
            "SELECT id FROM item WHERE source_ref = 'harvest-test:vanishes'",
        ), 'a vanished feature keeps its row');
    }

    /**
     * Two taps 30 m apart are both inside a 50 m radius of one node. Letting
     * both attach would point two rows at one node, and the suppression that
     * hides the raw pin assumes a single claimant. Measured on the Dutch
     * register: 10 nodes out of 2515 were contested by exactly two taps.
     */
    public function testOneOsmNodeIsClaimedByOneFeatureOnly(): void
    {
        $provider = $this->provider(50);
        $this->coveragePoi('node/99903', self::LAT, self::LNG);

        $counts = $this->harvest()->apply($provider, [
            $this->feature('harvest-test:near', self::LAT, self::LNG),
            // ~28 m north: inside the radius, but the node is taken.
            $this->feature('harvest-test:alsonear', self::LAT + 0.00025, self::LNG),
        ]);

        self::assertSame(2, $counts['inserted'], 'the second is still a real place');
        self::assertSame(1, $counts['attached']);
        self::assertSame(1, $counts['contested']);
        self::assertSame(1, (int) $this->db()->fetchOne(
            "SELECT COUNT(*) FROM item WHERE osm_ref = 'node/99903'",
        ));
        self::assertNull($this->db()->fetchOne(
            "SELECT osm_ref FROM item WHERE source_ref = 'harvest-test:alsonear'",
        ));
    }

    /**
     * A letter is not a kind. Letter B holds 7024 rows in the Netherlands and
     * only 2744 of them are taps; matching by letter alone tied a public tap
     * to the café across the road, and pushed the attach count 4% above the
     * number the spec measured for taps alone (measured 2026-09-04).
     */
    public function testTheMatchIsNarrowedToTagsThatMeanTheSameThing(): void
    {
        $provider = $this->provider(50);
        $provider->setMatchTags(['amenity' => ['drinking_water']]);
        $this->em()->flush();
        // A café, in the same letter, right on top of the tap.
        $this->coveragePoi('node/99904', self::LAT, self::LNG, ['amenity' => 'cafe']);

        $counts = $this->harvest()->apply($provider, [$this->feature('harvest-test:cafe', self::LAT, self::LNG)]);

        self::assertSame(0, $counts['attached'], 'a tap is not the café it stands beside');
        self::assertNull($this->db()->fetchOne(
            "SELECT osm_ref FROM item WHERE source_ref = 'harvest-test:cafe'",
        ));
    }

    public function testTheSameTapDoesMatchANodeCarryingAnAcceptedTag(): void
    {
        $provider = $this->provider(50);
        $provider->setMatchTags(['amenity' => ['drinking_water', 'water_point']]);
        $this->em()->flush();
        $this->coveragePoi('node/99905', self::LAT, self::LNG, ['amenity' => 'water_point']);

        $counts = $this->harvest()->apply($provider, [$this->feature('harvest-test:wp', self::LAT, self::LNG)]);

        self::assertSame(1, $counts['attached']);
    }

    // --- the command ------------------------------------------------------

    public function testTheCommandIsDryUntilToldOtherwise(): void
    {
        $this->provider(50, 'harvest-cmd');
        $file = $this->writeFile([$this->geoFeature('harvest-test:dry', self::LAT, self::LNG)]);

        $tester = $this->harvestCommand(['provider' => 'harvest-cmd', 'file' => $file]);
        $tester->assertCommandIsSuccessful();

        self::assertStringContainsString('Dry run', $tester->getDisplay());
        self::assertFalse($this->db()->fetchOne("SELECT id FROM item WHERE source_ref = 'harvest-test:dry'"));
    }

    /**
     * EPSG:28992 metres read as degrees land in the hundreds of thousands.
     * Writing that into the catalogue would put a Dutch tap in the ocean.
     */
    public function testAnUnreprojectedCoordinateIsRefused(): void
    {
        $this->provider(50, 'harvest-proj');
        $file = $this->writeFile([$this->geoFeature('harvest-test:proj', 519000.0, 105000.0)]);

        $tester = $this->harvestCommand(['provider' => 'harvest-proj', 'file' => $file, '--write' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('reprojected', $tester->getDisplay());
    }

    /** A letter the registry does not claim is a field map on the wrong layer. */
    public function testALetterTheProviderDoesNotFillIsRefused(): void
    {
        $provider = $this->provider(50, 'harvest-letter');
        $provider->setLetters(['B']);
        $this->em()->flush();

        $feature = $this->geoFeature('harvest-test:letter', self::LAT, self::LNG);
        $feature['properties']['letter'] = 'O';
        $file = $this->writeFile([$feature]);

        $tester = $this->harvestCommand(['provider' => 'harvest-letter', 'file' => $file, '--write' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('does not fill', $tester->getDisplay());
    }

    /** An empty file is a fetch that failed quietly, not an empty publisher. */
    public function testAnEmptyFetchIsRefusedRatherThanTreatedAsData(): void
    {
        $this->provider(50, 'harvest-empty');
        $file = $this->writeFile([]);

        $tester = $this->harvestCommand(['provider' => 'harvest-empty', 'file' => $file, '--write' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('empty fetch', $tester->getDisplay());
    }

    /** Paused means "keep the rows, stop refreshing". */
    public function testAPausedProviderIsNotRefreshed(): void
    {
        $provider = $this->provider(50, 'harvest-paused');
        $provider->setEnabled(false);
        $this->em()->flush();
        $file = $this->writeFile([$this->geoFeature('harvest-test:paused', self::LAT, self::LNG)]);

        $tester = $this->harvestCommand(['provider' => 'harvest-paused', 'file' => $file, '--write' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('paused', $tester->getDisplay());
    }

    // --- helpers ----------------------------------------------------------

    /** @return array{ref: string, letter: string, name: string, lat: float, lng: float, attributes: array<string, mixed>, country_code: string|null} */
    private function feature(string $ref, float $lat, float $lng): array
    {
        return [
            'ref' => $ref,
            'letter' => 'B',
            'name' => 'Test tap',
            'lat' => $lat,
            'lng' => $lng,
            'attributes' => ['potable' => 'yes'],
            'country_code' => 'BE',
        ];
    }

    /** @return array<string, mixed> */
    private function geoFeature(string $ref, float $lat, float $lng): array
    {
        return [
            'type' => 'Feature',
            'properties' => ['ref' => $ref, 'letter' => 'B', 'name' => 'Test tap', 'potable' => 'yes'],
            'geometry' => ['type' => 'Point', 'coordinates' => [$lng, $lat]],
        ];
    }

    /** @param list<array<string, mixed>> $features */
    private function writeFile(array $features): string
    {
        $path = sys_get_temp_dir().'/provider-harvest-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($path, json_encode(['type' => 'FeatureCollection', 'features' => $features], \JSON_THROW_ON_ERROR));

        return $path;
    }

    /** @param array<string, mixed> $args */
    private function harvestCommand(array $args): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:providers:harvest'));
        $tester->execute($args);

        return $tester;
    }

    private function provider(int $radius, string $key = 'harvest-test'): DataProvider
    {
        $em = $this->em();
        $existing = $em->getRepository(DataProvider::class)->findOneBy(['key' => $key]);
        if (null !== $existing) {
            $existing->setMatchRadiusM($radius);
            $existing->setEnabled(true);
            $em->flush();

            return $existing;
        }

        $provider = new DataProvider($key, 'Harvest test', 'Harvest test, in full', 'https://example.test/', 'CC0 1.0', 'cc0-1.0', 10);
        $provider->setMatchRadiusM($radius);
        $provider->setCountryCode('BE');
        $em->persist($provider);
        $em->flush();

        return $provider;
    }

    /** @param array<string, string> $tags */
    private function coveragePoi(string $ref, float $lat, float $lng, array $tags = []): void
    {
        $this->db()->executeStatement(
            "INSERT INTO coverage_poi (ref, letter, name, geom, tags, country_code)
             VALUES (:ref, 'B', 'OSM tap', ST_SetSRID(ST_MakePoint(:lng, :lat), 4326), :tags, 'BE')",
            ['ref' => $ref, 'lat' => $lat, 'lng' => $lng, 'tags' => json_encode($tags, \JSON_THROW_ON_ERROR)],
        );
    }

    private function harvest(): ProviderHarvest
    {
        return static::getContainer()->get(ProviderHarvest::class);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }
}
