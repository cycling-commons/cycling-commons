<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Command;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * `app:catalog:seed-wikidata` (test-suite review 2026-08-24).
 *
 * This command writes catalog rows a rider then sees on the map, from an
 * artifact harvested by tools/wikimedia/country_places.py, and it had no test
 * on either side of the boundary. Its three guards are the ones that matter and
 * the ones that fail quietly: dry-run must write nothing, a border place must
 * be seeded once and not once per country, and a place in no onboarded region
 * must be skipped rather than pinned somewhere that has no page.
 *
 * @see docs/specs/catalog-data-model.md §3
 */
final class SeedWikidataPlacesCommandTest extends KernelTestCase
{
    private Connection $db;
    private string $dir;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = static::getContainer()->get(Connection::class);
        $this->dir = (string) tempnam(sys_get_temp_dir(), 'seedwd');
        unlink($this->dir);
        mkdir($this->dir);

        // A region that contains the fixture point. `regionFor()` refuses to
        // write a place outside every operational region, so without this the
        // command would correctly seed nothing and every assertion would be
        // measuring the wrong thing.
        $this->db->executeStatement(
            "INSERT INTO region (slug, name, country_code, area_km2, geom, created_at, updated_at)
             VALUES ('seed-wd-test', 'Seed WD Test', 'BE', 100,
                     ST_Multi(ST_GeomFromText('POLYGON((5.0 50.0, 5.2 50.0, 5.2 50.2, 5.0 50.2, 5.0 50.0))', 4326)),
                     NOW(), NOW())",
        );
    }

    #[\Override]
    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
        parent::tearDown();
    }

    public function testDryRunReportsAndWritesNothing(): void
    {
        $this->artifact('be', [$this->place('Q1', 'Test Viewpoint')]);

        $tester = $this->run_(['--dry-run' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Would seed', $tester->getDisplay());
        // The whole run is one transaction that is rolled back. An operator
        // reviewing an artifact must be able to trust that.
        self::assertSame(0, $this->seededCount());
    }

    public function testARealRunSeedsAnUnverifiedWikidataRow(): void
    {
        $this->artifact('be', [$this->place('Q1', 'Test Viewpoint')]);

        $tester = $this->run_();

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Seeded', $tester->getDisplay());

        $row = $this->db->fetchAssociative(
            "SELECT state, source, letter, country_code FROM item WHERE source_ref = 'wikidata:Q1'",
        );
        self::assertIsArray($row);
        // Harvested is never verified: a machine found it, a human has not
        // looked at it.
        self::assertSame('unverified', $row['state']);
        self::assertSame('wikidata', $row['source']);
        self::assertSame('BE', $row['country_code']);
    }

    public function testTheCommonsPhotoCreditSurvivesIntoTheRow(): void
    {
        $this->artifact('be', [$this->place('Q1', 'Test Viewpoint')]);

        $this->run_();

        $attrs = (string) $this->db->fetchOne(
            "SELECT attributes FROM item WHERE source_ref = 'wikidata:Q1'",
        );
        // Attribution is a licence obligation, not a nice-to-have: a CC BY-SA
        // photo seeded without its credit is a breach on a public page.
        self::assertStringContainsString('Jane Rider', $attrs);
        self::assertStringContainsString('CC BY-SA 3.0', $attrs);
    }

    public function testABorderPlaceIsSeededOnceAndTheSkipIsExplained(): void
    {
        // Same Q-id in two country artifacts: one lake, two sovereignties.
        $this->artifact('be', [$this->place('Q7', 'Border Lake')]);
        $this->artifact('nl', [$this->place('Q7', 'Border Lake')]);

        $tester = $this->run_();

        $tester->assertCommandIsSuccessful();
        self::assertSame(1, $this->seededCount('wikidata:Q7'));
        self::assertStringContainsString('a border shares with another country', $tester->getDisplay());
    }

    public function testAPlaceOutsideEveryOnboardedRegionIsSkippedByName(): void
    {
        // Sovereignty is not geography: the harvest bbox can hand over a point
        // no region covers, and a pin there has no page to sit on.
        $this->artifact('be', [$this->place('Q9', 'Far Away Rock', lat: -25.345, lng: 131.036)]);

        $tester = $this->run_();

        $tester->assertCommandIsSuccessful();
        self::assertSame(0, $this->seededCount('wikidata:Q9'));
        self::assertStringContainsString('no onboarded region', $tester->getDisplay());
        self::assertStringContainsString('Far Away Rock', $tester->getDisplay());
    }

    public function testTheCountryFilterSelectsArtifacts(): void
    {
        $this->artifact('be', [$this->place('Q1', 'Belgian Viewpoint')]);
        $this->artifact('nl', [$this->place('Q2', 'Dutch Viewpoint')]);

        $this->run_(['--country' => ['be']]);

        self::assertSame(1, $this->seededCount('wikidata:Q1'));
        self::assertSame(0, $this->seededCount('wikidata:Q2'));
    }

    public function testAnEmptyDirectoryIsAnErrorNotASilentSuccess(): void
    {
        // A cron or an operator who points at the wrong path must be told,
        // not congratulated on seeding nothing.
        $tester = $this->run_();

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('No places-*.json artifacts', $tester->getDisplay());
    }

    public function testTheVocabularyGuardChecksKeysAndNotValues(): void
    {
        // Worth pinning because it is easy to assume otherwise:
        // AttributeVocabulary::assertValid compares attribute KEYS against the
        // registry and never looks at the values. A harvest that invents a
        // `type` string is NOT caught here, so the review of the artifact is
        // the only thing standing between it and the catalog.
        $place = $this->place('Q1', 'Test Viewpoint');
        $place['type'] = 'Not A Registry Value';
        $this->artifact('be', [$place]);

        $tester = $this->run_();

        $tester->assertCommandIsSuccessful();
        self::assertSame(
            'Not A Registry Value',
            json_decode((string) $this->db->fetchOne(
                "SELECT attributes FROM item WHERE source_ref = 'wikidata:Q1'",
            ), true)['type'],
        );
    }

    /** @param array<string, mixed> $args */
    private function run_(array $args = []): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:catalog:seed-wikidata'));
        $tester->execute(['dir' => $this->dir] + $args);

        return $tester;
    }

    /** @param list<array<string, mixed>> $scenic */
    private function artifact(string $cc, array $scenic): void
    {
        file_put_contents(
            $this->dir.'/places-'.$cc.'.json',
            json_encode([
                'country' => strtoupper($cc),
                'scenic' => $scenic,
                'history' => [],
            ], \JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, mixed> */
    private function place(string $qid, string $name, float $lat = 50.1, float $lng = 5.1): array
    {
        return [
            'qid' => $qid,
            'name' => $name,
            'type' => 'Viewpoint / high point',
            'note' => 'a fixture place',
            'lat' => $lat,
            'lng' => $lng,
            'photo' => [
                'file' => 'Fixture.jpg',
                'credit' => 'Jane Rider',
                'user' => 'JaneR',
                'license' => 'CC BY-SA 3.0',
            ],
        ];
    }

    private function seededCount(?string $ref = null): int
    {
        return null === $ref
            ? (int) $this->db->fetchOne("SELECT COUNT(*) FROM item WHERE source = 'wikidata' AND source_ref LIKE 'wikidata:Q%'")
            : (int) $this->db->fetchOne('SELECT COUNT(*) FROM item WHERE source_ref = :ref', ['ref' => $ref]);
    }
}
