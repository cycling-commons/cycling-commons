<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Provider;

use App\Provider\Entity\DataProvider;
use App\Provider\ProviderHarvest;
use App\Tests\Coverage\CoverageSchema;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A register record gives way to a place the map already shows as ours,
 * whatever its source: a rider's, and an OpenStreetMap copy a person has
 * changed or confirmed (owner 2026-10-08, 2026-10-11).
 *
 * Found on production: "Waterpunt Geestmerambacht", an OSM copy riders had
 * photographed and confirmed, got an RIVM tap 13 m beside it, because the
 * import only knew rider sources.
 *
 * @see docs/specs/data-provider-hierarchy.md §5 rule 4
 */
final class HarvestBesideOursTest extends KernelTestCase
{
    use CoverageSchema;

    private const float LAT = 52.661111;
    private const float LNG = 4.771111;

    protected function setUp(): void
    {
        self::bootKernel();
        self::ensureCoverageSchema($this->db());
    }

    public function testATapBesideAConfirmedOsmCopyIsNotAdded(): void
    {
        $ours = $this->osmCopy('verified');

        $counts = $this->harvest()->apply($this->provider(), [$this->feature('beside-test:confirmed', self::LAT + 0.0001)]);

        self::assertSame(0, $counts['inserted'], 'no second tap 11 m beside ours');
        self::assertSame(1, $counts['skipped_rider']);
        self::assertSame(0, $this->rows('beside-test:confirmed'));
        self::assertSame('verified', $this->state($ours));
    }

    public function testATapBesideAnUntouchedOsmCopyIsStillAdded(): void
    {
        // A copy nobody changed or confirmed is the provider's ladder rung
        // below the register (ItemSource::dedupeRank): the register is the
        // better record of that place.
        $this->osmCopy('unverified');

        $counts = $this->harvest()->apply($this->provider(), [$this->feature('beside-test:untouched', self::LAT + 0.0001)]);

        self::assertSame(1, $counts['inserted']);
    }

    public function testAnEarlierRegisterRowBesideOursIsRetiredOnTheNextRun(): void
    {
        $feature = $this->feature('beside-test:earlier', self::LAT + 0.0001);
        $this->harvest()->apply($this->provider(), [$feature]);
        self::assertSame(1, $this->rows('beside-test:earlier'));
        // Then the place became ours: the OSM copy was confirmed.
        $this->osmCopy('verified');

        $counts = $this->harvest()->apply($this->provider(), [$feature]);

        self::assertSame(1, $counts['retired_beside_ours']);
        self::assertSame('retired', $this->stateByRef('beside-test:earlier'));
        self::assertSame(1, (int) $this->db()->fetchOne(
            "SELECT COUNT(*) FROM change_history h JOIN item i ON i.id = h.item_id WHERE i.source_ref = 'beside-test:earlier' AND h.field = 'state' AND h.changed_by = 0",
        ), 'the retirement is on record, by the system');
    }

    public function testARegisterRowAPersonTouchedStays(): void
    {
        $feature = $this->feature('beside-test:touched', self::LAT + 0.0001);
        $this->harvest()->apply($this->provider(), [$feature]);
        $id = (int) $this->db()->fetchOne("SELECT id FROM item WHERE source_ref = 'beside-test:touched'");
        $this->db()->executeStatement(
            "INSERT INTO change_history (item_id, field, old_value, new_value, changed_by, changed_at) VALUES (?, 'name', '\"\"', '\"Kraan\"', 77, NOW())",
            [$id],
        );
        $this->osmCopy('verified');

        $counts = $this->harvest()->apply($this->provider(), [$feature]);

        self::assertSame(0, $counts['retired_beside_ours'], 'a person\'s work is never retired by an import');
        self::assertNotSame('retired', $this->stateByRef('beside-test:touched'));
    }

    // --- helpers ----------------------------------------------------------

    private function osmCopy(string $state): int
    {
        return (int) $this->db()->fetchOne(
            "INSERT INTO item (letter, name, geom, country_code, state, source, source_ref, osm_ref, attributes, created_at, updated_at, imported_at)
             VALUES ('B', 'Waterpunt beside test', ST_SetSRID(ST_MakePoint(:lng, :lat), 4326), 'NL', :state, 'osm', 'node/990001', 'node/990001', '{}', NOW(), NOW(), NOW())
             RETURNING id",
            ['lat' => self::LAT, 'lng' => self::LNG, 'state' => $state],
        );
    }

    private function rows(string $ref): int
    {
        return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM item WHERE source_ref = ?', [$ref]);
    }

    private function state(int $id): string
    {
        return (string) $this->db()->fetchOne('SELECT state FROM item WHERE id = ?', [$id]);
    }

    private function stateByRef(string $ref): string
    {
        return (string) $this->db()->fetchOne('SELECT state FROM item WHERE source_ref = ?', [$ref]);
    }

    /** @return array{ref: string, letter: string, name: string, lat: float, lng: float, attributes: array<string, mixed>, country_code: string|null} */
    private function feature(string $ref, float $lat): array
    {
        return ['ref' => $ref, 'letter' => 'B', 'name' => '', 'lat' => $lat, 'lng' => self::LNG, 'attributes' => ['potable' => 'yes'], 'country_code' => 'NL'];
    }

    private function provider(): DataProvider
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $existing = $em->getRepository(DataProvider::class)->findOneBy(['key' => 'beside-test']);
        if (null !== $existing) {
            return $existing;
        }
        $provider = new DataProvider('beside-test', 'Beside test', 'Beside test, in full', 'https://example.test/', 'CC0 1.0', 'cc0-1.0', 10);
        $provider->setMatchRadiusM(15);
        $provider->setCountryCode('NL');
        $em->persist($provider);
        $em->flush();

        return $provider;
    }

    private function harvest(): ProviderHarvest
    {
        return static::getContainer()->get(ProviderHarvest::class);
    }

    private function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }
}
