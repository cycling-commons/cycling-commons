<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Provider;

use App\Catalog\Entity\Item;
use App\Provider\Entity\DataProvider;
use App\Provider\ProviderHarvest;
use App\Tests\Coverage\CoverageSchema;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `item.imported_at` means "last seen in an upstream export"
 * (data-provider-hierarchy.md §6.7.6). Every harvest that carries a row
 * stamps it, whether the row was inserted, changed, or left alone. A row the
 * publisher dropped keeps its old date and falls off rung 4 by itself.
 */
final class ProviderHarvestImportedAtTest extends KernelTestCase
{
    use CoverageSchema;

    private const float LAT = 50.111111;
    private const float LNG = 4.222222;
    private const string FIRST_RUN = '2026-09-01 08:00:00';
    private const string SECOND_RUN = '2026-09-08 08:00:00';

    protected function setUp(): void
    {
        self::bootKernel();
        self::ensureCoverageSchema($this->db());
        $this->db()->executeStatement("DELETE FROM item WHERE source_ref LIKE 'seen-test:%' OR name = 'Seen test rider tap'");
        $this->db()->executeStatement('DELETE FROM change_history WHERE item_id NOT IN (SELECT id FROM item)');
    }

    public function testImportedAtIsSettableAndReadsBackTheSameInstant(): void
    {
        $seen = new \DateTimeImmutable('2026-09-10 08:00:00');
        $item = new Item();

        $item->setImportedAt($seen);

        self::assertSame(
            $seen->format(\DATE_ATOM),
            $item->getImportedAt()?->format(\DATE_ATOM),
            'imported_at must round-trip: rung 4 reads it as the last upstream sighting.',
        );
    }

    public function testConstructorSeedsImportedAtSoAFreshRowIsNeverNull(): void
    {
        self::assertNotNull(
            (new Item())->getImportedAt(),
            'A row with no sighting date cannot be placed on the ladder at all.',
        );
    }

    public function testAnInsertedRowCarriesTheRunTime(): void
    {
        $this->harvest()->apply($this->provider(), [$this->feature('seen-test:insert')], new \DateTimeImmutable(self::FIRST_RUN));

        self::assertSame(self::FIRST_RUN, $this->importedAt('seen-test:insert'));
    }

    public function testAnUnchangedRowStillAdvancesOnTheNextRun(): void
    {
        $feature = $this->feature('seen-test:unchanged');
        $this->harvest()->apply($this->provider(), [$feature], new \DateTimeImmutable(self::FIRST_RUN));

        $this->harvest()->apply($this->provider(), [$feature], new \DateTimeImmutable(self::SECOND_RUN));

        self::assertSame(
            self::SECOND_RUN,
            $this->importedAt('seen-test:unchanged'),
            'Republishing a row unchanged is still a sighting.',
        );
    }

    /**
     * The one path the loop skips: the export carries the row, but a rider
     * pin sits at the spot so the provider row is left untouched. The
     * publisher still lists it, so the date must move.
     */
    public function testARowSkippedForARiderPinIsStillASighting(): void
    {
        $feature = $this->feature('seen-test:skipped');
        $this->harvest()->apply($this->provider(), [$feature], new \DateTimeImmutable(self::FIRST_RUN));
        $this->db()->executeStatement(
            "INSERT INTO item (letter, name, geom, country_code, state, source, source_ref, attributes, created_at, updated_at, imported_at)
             VALUES ('B', 'Seen test rider tap', ST_SetSRID(ST_MakePoint(:lng, :lat), 4326), 'BE', 'unverified', 'user', 'seen-test:rider', '{}', NOW(), NOW(), NOW())",
            ['lat' => self::LAT, 'lng' => self::LNG],
        );

        $counts = $this->harvest()->apply($this->provider(), [$feature], new \DateTimeImmutable(self::SECOND_RUN));

        self::assertSame(1, $counts['skipped_rider'], 'the loop took the rider branch');
        self::assertSame(self::SECOND_RUN, $this->importedAt('seen-test:skipped'));
    }

    public function testARowThePublisherDroppedKeepsItsLastSighting(): void
    {
        $this->harvest()->apply($this->provider(), [$this->feature('seen-test:kept'), $this->feature('seen-test:dropped', self::LAT + 0.01)], new \DateTimeImmutable(self::FIRST_RUN));

        $counts = $this->harvest()->apply($this->provider(), [$this->feature('seen-test:kept')], new \DateTimeImmutable(self::SECOND_RUN));

        self::assertSame(1, $counts['stale']);
        self::assertSame(self::SECOND_RUN, $this->importedAt('seen-test:kept'));
        self::assertSame(self::FIRST_RUN, $this->importedAt('seen-test:dropped'), 'a vanished row stops advancing, nothing else marks it');
    }

    // --- helpers ----------------------------------------------------------

    private function importedAt(string $ref): string
    {
        return (string) $this->db()->fetchOne(
            "SELECT to_char(imported_at, 'YYYY-MM-DD HH24:MI:SS') FROM item WHERE source_ref = :ref",
            ['ref' => $ref],
        );
    }

    /** @return array{ref: string, letter: string, name: string, lat: float, lng: float, attributes: array<string, mixed>, country_code: string|null} */
    private function feature(string $ref, float $lat = self::LAT, float $lng = self::LNG): array
    {
        return [
            'ref' => $ref,
            'letter' => 'B',
            'name' => 'Seen test tap',
            'lat' => $lat,
            'lng' => $lng,
            'attributes' => ['potable' => 'yes'],
            'country_code' => 'BE',
        ];
    }

    private function provider(): DataProvider
    {
        $em = $this->em();
        $existing = $em->getRepository(DataProvider::class)->findOneBy(['key' => 'seen-test']);
        if (null !== $existing) {
            return $existing;
        }

        $provider = new DataProvider('seen-test', 'Seen test', 'Seen test, in full', 'https://example.test/', 'CC0 1.0', 'cc0-1.0', 10);
        $provider->setMatchRadiusM(50);
        $provider->setCountryCode('BE');
        $em->persist($provider);
        $em->flush();

        return $provider;
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
