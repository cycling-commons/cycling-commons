<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Command;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * `app:catalog:seed-cycling-history`.
 *
 * The list is world wide and most of it falls outside the onboarded regions on
 * any given day, so the guards are the point: a regionless place is named and
 * skipped, a place another row already is (by name, by Wikidata item or by OSM
 * object) is not seeded twice, and a dry run writes nothing.
 *
 * @see docs/specs/catalog-data-model.md §5
 */
final class SeedCyclingHistoryCommandTest extends KernelTestCase
{
    private Connection $db;
    private string $file;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = static::getContainer()->get(Connection::class);
        $this->file = (string) tempnam(sys_get_temp_dir(), 'seedch');

        $this->db->executeStatement(
            "INSERT INTO region (slug, name, country_code, area_km2, geom, created_at, updated_at)
             VALUES ('seed-ch-test', 'Seed CH Test', 'BE', 100,
                     ST_Multi(ST_GeomFromText('POLYGON((5.0 50.0, 5.2 50.0, 5.2 50.2, 5.0 50.2, 5.0 50.0))', 4326)),
                     NOW(), NOW())",
        );
    }

    #[\Override]
    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->file);
        parent::tearDown();
    }

    public function testDryRunReportsAndWritesNothing(): void
    {
        $this->artifact([$this->place('Test Velodrome')]);

        $tester = $this->run_(['--dry-run' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Would seed 1 place(s)', $tester->getDisplay());
        self::assertSame(0, $this->rows('manual:history-test-velodrome'));
    }

    public function testARealRunSeedsAnUnverifiedManualRowWithItsStoryKindAndOsmObject(): void
    {
        $this->artifact([$this->place('Test Velodrome', osmRef: 'way/42')]);

        $this->run_()->assertCommandIsSuccessful();

        $row = $this->db->fetchAssociative(
            "SELECT state, source, letter, country_code, osm_ref, region_id, attributes FROM item WHERE source_ref = 'manual:history-test-velodrome'",
        );
        self::assertIsArray($row);
        self::assertSame('unverified', $row['state']);
        self::assertSame('manual', $row['source']);
        self::assertSame('Q', $row['letter']);
        self::assertSame('BE', $row['country_code']);
        // The row is the OSM object, so the raw OSM pin stays off the map.
        self::assertSame('way/42', $row['osm_ref']);
        self::assertNotNull($row['region_id']);
        /** @var array<string, mixed> $attrs */
        $attrs = json_decode((string) $row['attributes'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(['type' => 'heritage', 'cyclingStory' => 'A rider won here.'], $attrs);
    }

    public function testTheYearTheOfficialSiteAndTheWikipediaLinksReachTheRow(): void
    {
        $place = $this->place('Test Velodrome');
        $place['web'] = 'https://velodrome.example';
        $place['year'] = '1903';
        $place['links'] = [
            ['label' => 'Wikipedia', 'urls' => [['locale' => 'en', 'url' => 'https://en.wikipedia.org/wiki/Test_Velodrome']]],
            ['label' => 'A Rider', 'urls' => [['locale' => 'en', 'url' => 'https://en.wikipedia.org/wiki/A_Rider'], ['locale' => 'nl', 'url' => 'https://nl.wikipedia.org/wiki/A_Rider']]],
        ];
        $this->artifact([$place]);

        $this->run_()->assertCommandIsSuccessful();

        /** @var array<string, mixed> $attrs */
        $attrs = json_decode((string) $this->db->fetchOne(
            "SELECT attributes FROM item WHERE source_ref = 'manual:history-test-velodrome'",
        ), true, 512, \JSON_THROW_ON_ERROR);
        // One slot per fact (catalog-data-model.md §7): the site in `web`, the articles in `links`.
        self::assertSame('https://velodrome.example', $attrs['web'] ?? null);
        self::assertSame('1903', $attrs['year'] ?? null);
        // jsonb sorts the keys, so the comparison ignores their order.
        self::assertEquals($place['links'], $attrs['links'] ?? null);
    }

    public function testAFreePhotoIsStoredWithItsCreditAndANonFreeOneIsLeftOff(): void
    {
        $free = $this->place('Test Velodrome');
        $free['photo'] = ['file' => 'Test velodrome.jpg', 'credit' => 'Jane Rider', 'user' => 'JaneR', 'license' => 'CC BY-SA 4.0'];
        $unfree = $this->place('Other Velodrome', lat: 50.12, lng: 5.12);
        $unfree['photo'] = ['file' => 'Other.jpg', 'credit' => 'Jane Rider', 'user' => 'JaneR', 'license' => 'CC BY-NC-SA 4.0'];
        $this->artifact([$free, $unfree]);

        $tester = $this->run_();

        $tester->assertCommandIsSuccessful();
        $photo = (string) $this->db->fetchOne("SELECT attributes->'photo' FROM item WHERE source_ref = 'manual:history-test-velodrome'");
        // Attribution travels with the photo: a CC BY-SA file without its credit is a licence breach.
        self::assertStringContainsString('Jane Rider', $photo);
        self::assertStringContainsString('CC BY-SA 4.0', $photo);
        self::assertNull($this->db->fetchOne("SELECT attributes->'photo' FROM item WHERE source_ref = 'manual:history-other-velodrome'"), 'the place is seeded, its photo is not');
        self::assertStringContainsString('Other Velodrome (BE): licence', self::flat($tester));
    }

    public function testAReSeedKeepsOurOwnCopyOfTheSamePhoto(): void
    {
        $place = $this->place('Test Velodrome');
        $place['photo'] = ['file' => 'Test vélodrome.jpg', 'credit' => 'Jane Rider', 'user' => 'JaneR', 'license' => 'CC BY-SA 4.0'];
        $this->artifact([$place]);
        $this->run_()->assertCommandIsSuccessful();
        // What app:media:localise-commons leaves: our URLs, and the same Commons page URL-encoded.
        $this->db->executeStatement(
            "UPDATE item SET attributes = jsonb_set(jsonb_set(attributes, '{photo,sm}', '\"/media/own/sm.webp\"'), '{photo,source}', :src::jsonb)
              WHERE source_ref = 'manual:history-test-velodrome'",
            ['src' => json_encode('https://commons.wikimedia.org/wiki/File:'.rawurlencode('Test_vélodrome.jpg'), \JSON_THROW_ON_ERROR)],
        );

        $this->run_()->assertCommandIsSuccessful();

        self::assertSame('/media/own/sm.webp', $this->db->fetchOne(
            "SELECT attributes->'photo'->>'sm' FROM item WHERE source_ref = 'manual:history-test-velodrome'",
        ), 'a re-seed must not put the Wikimedia link back');
    }

    public function testALinkTheMapWouldRefuseFailsTheRun(): void
    {
        $place = $this->place('Test Velodrome');
        $place['links'] = [['label' => 'Official site', 'urls' => [['url' => 'https://velodrome.example']]]];
        $this->artifact([$place]);

        $tester = $this->run_();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('belongs in the web attribute', self::flat($tester));
        self::assertSame(0, $this->rows('manual:history-test-velodrome'));
    }

    public function testASecondRunUpdatesTheSameRow(): void
    {
        $this->artifact([$this->place('Test Velodrome')]);
        $this->run_()->assertCommandIsSuccessful();

        $this->artifact([$this->place('Test Velodrome', story: 'Two riders won here.')]);
        $this->run_()->assertCommandIsSuccessful();

        self::assertSame(1, $this->rows('manual:history-test-velodrome'));
        self::assertStringContainsString('Two riders', (string) $this->db->fetchOne(
            "SELECT attributes FROM item WHERE source_ref = 'manual:history-test-velodrome'",
        ));
    }

    public function testAPlaceOutsideEveryOnboardedRegionIsSkippedByName(): void
    {
        $this->artifact([$this->place('Far Velodrome', lat: 19.36, lng: -99.17, cc: 'MX')]);

        $tester = $this->run_();

        $tester->assertCommandIsSuccessful();
        self::assertSame(0, $this->rows('manual:history-far-velodrome'));
        self::assertStringContainsString('Far Velodrome (MX)', $tester->getDisplay());
        self::assertStringContainsString('no onboarded region', $tester->getDisplay());
    }

    public function testAPlaceAnotherRowAlreadyIsIsNotSeededTwice(): void
    {
        // The same Wikidata item under another name, and the same OSM object:
        // neither is a name match, both are the same place.
        $this->db->executeStatement(
            "INSERT INTO item (letter, name, geom, country_code, state, source, source_ref, osm_ref, attributes, created_at, updated_at)
             VALUES ('Q', 'Wikidata Chapel', ST_SetSRID(ST_MakePoint(5.11, 50.11), 4326), 'BE', 'unverified', 'wikidata', 'wikidata:Q77', NULL, '{}', NOW(), NOW()),
                    ('Q', 'OSM Memorial', ST_SetSRID(ST_MakePoint(5.12, 50.12), 4326), 'BE', 'unverified', 'user', 'u:1', 'node/99', '{}', NOW(), NOW())",
        );
        $this->artifact([
            $this->place('Chapel Of Cyclists', lat: 50.11, lng: 5.11, qid: 'Q77'),
            $this->place('A Rider Memorial', lat: 50.12, lng: 5.12, osmRef: 'node/99'),
        ]);

        $tester = $this->run_();

        $tester->assertCommandIsSuccessful();
        self::assertSame(0, $this->rows('manual:history-chapel-of-cyclists'));
        self::assertSame(0, $this->rows('manual:history-a-rider-memorial'));
        self::assertStringContainsString('"Wikidata Chapel" (wikidata) is the same place', self::flat($tester));
        self::assertStringContainsString('"OSM Memorial" (user) is the same place', self::flat($tester));
    }

    public function testAKindThatIsNoHistoryKindFailsTheRun(): void
    {
        $place = $this->place('Test Velodrome');
        $place['kind'] = 'waterfall';
        $this->artifact([$place]);

        $tester = $this->run_();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('not a History & culture kind', self::flat($tester));
        self::assertSame(0, $this->rows('manual:history-test-velodrome'));
    }

    public function testAMissingArtifactIsAnError(): void
    {
        (new Filesystem())->remove($this->file);

        $tester = $this->run_();

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('cycling_history.py', $tester->getDisplay());
    }

    /** @param array<string, mixed> $args */
    private function run_(array $args = []): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:catalog:seed-cycling-history'));
        $tester->execute(['file' => $this->file] + $args);

        return $tester;
    }

    /** The console output on one line: SymfonyStyle wraps a long note at 80 columns. */
    private static function flat(CommandTester $tester): string
    {
        return (string) preg_replace('/\s*\n\s*!?\s*/', ' ', $tester->getDisplay());
    }

    /** @param list<array<string, mixed>> $places */
    private function artifact(array $places): void
    {
        file_put_contents($this->file, json_encode(['places' => $places], \JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function place(string $name, float $lat = 50.1, float $lng = 5.1, string $cc = 'BE', ?string $qid = null, ?string $osmRef = null, string $story = 'A rider won here.'): array
    {
        return array_filter([
            'ref' => 'manual:history-'.strtolower(str_replace(' ', '-', $name)),
            'name' => $name,
            'kind' => 'heritage',
            'lat' => $lat,
            'lng' => $lng,
            'cc' => $cc,
            'story' => $story,
            'qid' => $qid,
            'osmRef' => $osmRef,
        ], static fn (mixed $v): bool => null !== $v);
    }

    private function rows(string $ref): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM item WHERE source_ref = :ref', ['ref' => $ref]);
    }
}
