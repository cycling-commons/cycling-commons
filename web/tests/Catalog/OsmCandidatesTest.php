<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\Import\OsmCandidates;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The OSM-candidate list is computed once and stored on the row; the desk
 * reads the column, never the coverage table (catalog-data-model.md §5b,
 * owner 2026-08-25: "why is this asked on every list view").
 *
 * Runs against the real schema: the point of the store is what the database
 * holds after each call.
 */
final class OsmCandidatesTest extends KernelTestCase
{
    private Connection $db;
    private OsmCandidates $store;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = static::getContainer()->get(Connection::class);
        $this->store = static::getContainer()->get(OsmCandidates::class);
        $this->db->beginTransaction();
        // The coverage tables are pipeline-owned (load.py) and absent from a
        // database only the app's migrations built. Inside this transaction
        // (rolled back in tearDown) a minimal copy is enough for the linker.
        if (null === $this->db->fetchOne("SELECT to_regclass('coverage_poi')")) {
            $this->db->executeStatement('CREATE TABLE coverage_source (id smallint GENERATED ALWAYS AS IDENTITY PRIMARY KEY, slug varchar(64) NOT NULL UNIQUE)');
            $this->db->executeStatement('CREATE TABLE coverage_poi (id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY, ref varchar(160) NOT NULL, letter char(1) NOT NULL, kind varchar(16), name varchar(255), geom geometry(Point, 4326) NOT NULL, tags jsonb NOT NULL, osm_version int, osm_ts timestamptz, src_region_id smallint NOT NULL REFERENCES coverage_source(id), country_code char(2), region_id int, UNIQUE (ref, letter))');
        }
    }

    protected function tearDown(): void
    {
        $this->db->rollBack();
        parent::tearDown();
    }

    /** A B row at the given point with an open OSM question; returns its id. */
    private function openItem(float $lat, float $lng): int
    {
        $this->db->executeStatement(
            "INSERT INTO item (letter, name, geom, country_code, state, source, source_ref, attributes, created_at, updated_at)
             VALUES ('B', 'Test tap', ST_SetSRID(ST_MakePoint(:lng, :lat), 4326), 'BE', 'submitted', 'user', :ref, '{}', NOW(), NOW())",
            ['lat' => $lat, 'lng' => $lng, 'ref' => 'sub:test-'.bin2hex(random_bytes(4))],
        );

        return (int) $this->db->fetchOne('SELECT lastval()');
    }

    /** A coverage water POI at the given point. */
    private function coverageTap(string $ref, float $lat, float $lng): void
    {
        $src = $this->db->fetchOne("SELECT id FROM coverage_source WHERE slug = 'test/osm-candidates'");
        if (false === $src) {
            $this->db->executeStatement("INSERT INTO coverage_source (slug) VALUES ('test/osm-candidates')");
            $src = $this->db->fetchOne('SELECT lastval()');
        }
        $this->db->executeStatement(
            "INSERT INTO coverage_poi (ref, letter, name, geom, tags, src_region_id, country_code)
             VALUES (:ref, 'B', 'OSM tap', ST_SetSRID(ST_MakePoint(:lng, :lat), 4326), '{}', :src, 'BE')",
            ['ref' => $ref, 'lat' => $lat, 'lng' => $lng, 'src' => (int) $src],
        );
    }

    public function testFirstReadComputesAndStoresTheList(): void
    {
        $this->coverageTap('node/900000001', 50.39120, 5.88160);
        $id = $this->openItem(50.39125, 5.88150);

        $before = $this->db->fetchAssociative('SELECT osm_candidates, osm_candidates_at FROM item WHERE id = :id', ['id' => $id]);
        self::assertNull($before['osm_candidates'], 'a fresh row carries no list yet');

        $lists = $this->store->forItems([$id]);
        self::assertSame(['node/900000001'], array_column($lists[$id], 'ref'));

        $after = $this->db->fetchAssociative('SELECT osm_candidates, osm_candidates_at FROM item WHERE id = :id', ['id' => $id]);
        self::assertNotNull($after['osm_candidates_at'], 'the list is stored with its timestamp');
        self::assertSame(['node/900000001'], array_column(json_decode((string) $after['osm_candidates'], true), 'ref'));
    }

    public function testSecondReadIsTheStoredListNotTheCoverageTable(): void
    {
        $this->coverageTap('node/900000002', 50.40120, 5.90160);
        $id = $this->openItem(50.40125, 5.90150);
        $this->store->forItems([$id]);

        // The coverage row disappears (a harvest, a delete): the stored list
        // stands until something clears it. That is the whole point: no
        // per-list-view query.
        $this->db->executeStatement("DELETE FROM coverage_poi WHERE ref = 'node/900000002'");
        $lists = $this->store->forItems([$id]);
        self::assertSame(['node/900000002'], array_column($lists[$id], 'ref'));
    }

    public function testAClearedListIsRecomputedOnTheNextRead(): void
    {
        $this->coverageTap('node/900000003', 50.41120, 5.91160);
        $id = $this->openItem(50.41125, 5.91150);
        $this->store->forItems([$id]);
        $this->db->executeStatement("DELETE FROM coverage_poi WHERE ref = 'node/900000003'");

        // What the pipeline's swap does for the country: NULL both columns.
        $this->db->executeStatement('UPDATE item SET osm_candidates = NULL, osm_candidates_at = NULL WHERE id = :id', ['id' => $id]);
        $lists = $this->store->forItems([$id]);
        self::assertSame([], $lists[$id], 'recomputed against what the coverage table holds now');
    }

    public function testAMoveRecomputesAtTheNewPoint(): void
    {
        $this->coverageTap('node/900000004', 50.42120, 5.92160);
        $id = $this->openItem(51.0, 4.0);                       // far away: nothing nearby
        self::assertSame([], $this->store->forItems([$id])[$id]);

        $this->store->refreshAt($id, 'B', 50.42125, 5.92150);   // the approved pin move
        self::assertSame(['node/900000004'], array_column($this->store->forItems([$id])[$id], 'ref'));
    }

    public function testAnAnsweredRowIsNotInTheAnswer(): void
    {
        $id = $this->openItem(50.43, 5.93);
        $this->db->executeStatement("UPDATE item SET osm_checked_at = NOW(), osm_ref = 'node/1' WHERE id = :id", ['id' => $id]);
        self::assertSame([], $this->store->forItems([$id]), 'nothing to offer once the question is answered');
    }
}
