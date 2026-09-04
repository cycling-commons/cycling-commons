<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\Import\DuplicateGuard;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * One place, one row (catalog-data-model.md §5).
 *
 * The guard exists because read-time dedupe matches **by ref**
 * (coverage-provider.md §5), and a PIVOT hotel row and an OSM hotel row have
 * different refs by construction — so a ref-based dedupe is structurally blind
 * to them being one hotel. Five such pairs were live on the map as two pins on
 * one building when this was written (2026-08-24).
 *
 * Both halves of the rule are load-bearing and each is tested on its own:
 * name alone would merge three real "St Mary's Cathedral" buildings on three
 * continents, and distance alone would merge a cafe and the bike shop next
 * door.
 */
final class DuplicateGuardTest extends KernelTestCase
{
    private Connection $db;
    private DuplicateGuard $guard;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = static::getContainer()->get(Connection::class);
        $this->guard = static::getContainer()->get(DuplicateGuard::class);
        $this->db->executeStatement("DELETE FROM item WHERE source_ref LIKE 'test:dup%'");
    }

    public function testAnEmptyCatalogHoldsNothing(): void
    {
        self::assertNull($this->guard->existing('O', 'Hôtel Koru', 50.66887, 4.90664));
    }

    public function testTheSameNameAtTheSamePlaceIsHeld(): void
    {
        $id = $this->seed('O', 'Hôtel Koru', 50.66887, 4.90664, ItemSource::Osm, 'a');

        $held = $this->guard->existing('O', 'Hôtel Koru', 50.66884, 4.90664);

        self::assertNotNull($held);
        self::assertSame($id, $held['id']);
        self::assertLessThan(10.0, $held['distance_m']);
    }

    public function testASpellingDifferenceStillMatches(): void
    {
        // The whole point of NameKey: two harvests spell one place differently
        // and a string comparison sees two places.
        $this->seed('P', 'Côte de Saint-Roch', 50.10000, 5.10000, ItemSource::Osm, 'b');

        self::assertNotNull($this->guard->existing('P', 'Cote de Saint Roch', 50.10000, 5.10000));
    }

    public function testTheSameNameFarAwayIsANamesakeNotADuplicate(): void
    {
        // St Mary's Cathedral is in Sydney, in Perth and in Tokyo. Three real
        // buildings; merging them would delete two of them.
        $this->seed('Q', "St Mary's Cathedral", -33.87111, 151.21333, ItemSource::Wikidata, 'c');

        self::assertNull($this->guard->existing('Q', 'St. Mary\'s Cathedral', 35.71417, 139.72667));
    }

    public function testADifferentNameAtTheSamePlaceIsNotADuplicate(): void
    {
        // A cafe and the bike shop next door share a doorway, not an identity.
        $this->seed('D', 'Cycles et Sacoches', 50.20000, 5.20000, ItemSource::Osm, 'd');

        self::assertNull($this->guard->existing('D', 'Bicyclic', 50.20001, 5.20001));
    }

    public function testADifferentLetterAtTheSamePlaceIsNotADuplicate(): void
    {
        // A climb and a viewpoint on one summit are two catalog entries about
        // two different things.
        $this->seed('P', 'Signal de Botrange', 50.50167, 6.09306, ItemSource::Osm, 'e');

        self::assertNull($this->guard->existing('N', 'Signal de Botrange', 50.50167, 6.09306));
    }

    public function testJustOutsideTheRadiusIsNotADuplicate(): void
    {
        // ~0.01 degrees of latitude is about 1.1 km, comfortably past 250 m.
        $this->seed('P', 'Point de Vue', 50.30000, 5.30000, ItemSource::Osm, 'f');

        self::assertNull($this->guard->existing('P', 'Point de Vue', 50.31000, 5.30000));
    }

    public function testARetiredRowIsNotInTheWay(): void
    {
        // This is what lets the two halves compose: once `app:catalog:dedupe`
        // retires a weaker row, the next import may admit the better one. If
        // retired rows still blocked, a duplicate would lock its own place out
        // of the catalog forever.
        $this->seed('O', 'Hôtel Koru', 50.66887, 4.90664, ItemSource::Osm, 'g', ItemState::Retired);

        self::assertNull($this->guard->existing('O', 'Hôtel Koru', 50.66887, 4.90664));
    }

    public function testARowDoesNotCollideWithItself(): void
    {
        // Re-importing is normal; every import would otherwise skip everything
        // it imported last time.
        $this->seed('O', 'Hôtel Koru', 50.66887, 4.90664, ItemSource::Authority, 'h');

        self::assertNull($this->guard->existing('O', 'Hôtel Koru', 50.66887, 4.90664, 'authority:test:dup:h'));
    }

    public function testAPunctuationOnlyNameNeverMatches(): void
    {
        // Its key is '', and an empty key would collide with every other
        // punctuation-only row in the catalog.
        $this->seed('P', '---', 50.40000, 5.40000, ItemSource::Osm, 'i');

        self::assertNull($this->guard->existing('P', '***', 50.40000, 5.40000));
    }

    public function testTheExplanationNamesTheRowInTheWayAndHowFar(): void
    {
        $this->seed('O', 'Hôtel Koru', 50.66887, 4.90664, ItemSource::Osm, 'j');
        $held = $this->guard->existing('O', 'Hôtel Koru', 50.66884, 4.90664);
        self::assertNotNull($held);

        $line = DuplicateGuard::explain('Hôtel Koru', ItemSource::Authority, $held);

        // "skipped 4 duplicates" is not actionable. The operator needs the id.
        self::assertStringContainsString('#'.$held['id'], $line);
        self::assertStringContainsString('osm', $line);
        // pivot outranks osm, so the note must point at the way out — otherwise
        // a canonical PIVOT row stays locked out by an OSM row forever while
        // the weekly import reports success.
        self::assertStringContainsString('outranks', $line);
        self::assertStringContainsString('app:catalog:dedupe', $line);
    }

    public function testTheExplanationIsQuietWhenTheHeldRowRanksHigher(): void
    {
        $this->seed('O', 'Hôtel Koru', 50.66887, 4.90664, ItemSource::Manual, 'k');
        $held = $this->guard->existing('O', 'Hôtel Koru', 50.66884, 4.90664);
        self::assertNotNull($held);

        $line = DuplicateGuard::explain('Hôtel Koru', ItemSource::Osm, $held);

        // Nothing to fix: the better row is already the one we have.
        self::assertStringNotContainsString('outranks', $line);
    }

    private function seed(
        string $letter,
        string $name,
        float $lat,
        float $lng,
        ItemSource $source,
        string $ref,
        ItemState $state = ItemState::Unverified,
    ): int {
        $this->db->executeStatement(
            'INSERT INTO item (letter, name, geom, country_code, state, source, source_ref, attributes, created_at, updated_at)
             VALUES (:letter, :name, ST_SetSRID(ST_MakePoint(:lng, :lat), 4326), :cc, :state, :source, :ref, :attrs, NOW(), NOW())',
            [
                'letter' => $letter, 'name' => $name, 'lat' => $lat, 'lng' => $lng,
                'cc' => 'BE', 'state' => $state->value, 'source' => $source->value,
                'ref' => 'test:dup:'.$ref, 'attrs' => '{}',
            ],
        );

        return (int) $this->db->fetchOne(
            'SELECT id FROM item WHERE source_ref = :ref',
            ['ref' => 'test:dup:'.$ref],
        );
    }
}
