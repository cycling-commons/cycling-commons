<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\CatalogStamps;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The stamps come from a count the database keeps itself (catalog_change,
 * Version20260928150000). These pin what moves a stamp, and what must not:
 * every rider refetches their regions when a stamp moves.
 */
final class CatalogStampsTest extends KernelTestCase
{
    private Connection $db;
    private CatalogStamps $stamps;
    private int $rid;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $this->stamps = static::getContainer()->get(CatalogStamps::class);

        $src = __DIR__.'/../fixtures/catalog';
        $dir = sys_get_temp_dir().'/catalog-stamps-'.getmypid();
        @mkdir($dir, 0777, true);
        foreach (['region-square.geojson', 'services.json'] as $f) {
            copy($src.'/'.$f, $dir.'/'.$f);
        }
        $tester = new CommandTester((new Application(self::$kernel))->find('app:catalog:import'));
        $tester->execute(['dir' => $dir]);
        $tester->assertCommandIsSuccessful();

        $this->rid = (int) $this->db->fetchOne('SELECT region_id FROM item WHERE region_id IS NOT NULL ORDER BY id LIMIT 1');
        self::assertGreaterThan(0, $this->rid, 'the fixture import region-stamps its rows');
    }

    /**
     * The stamp the old row count + latest timestamp pair could not tell
     * apart: two edits in the same second that leave the row count alone.
     */
    public function testEveryEditMovesItsRegionEvenTwoInOneSecond(): void
    {
        $s0 = $this->stamps->stamp($this->rid);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $s0, 'a compact hex stamp, URL-safe');
        self::assertSame($s0, $this->stamps->stamp($this->rid), 'stable while nothing changes');

        $edit = "UPDATE item SET name = name || '.' WHERE id = (SELECT min(id) FROM item WHERE region_id = :rid)";
        $this->db->executeStatement($edit, ['rid' => $this->rid]);
        $s1 = $this->stamps->stamp($this->rid);
        $this->db->executeStatement($edit, ['rid' => $this->rid]);
        $s2 = $this->stamps->stamp($this->rid);

        self::assertNotSame($s0, $s1);
        self::assertNotSame($s1, $s2, 'a second edit in the same second is a second change');
    }

    /**
     * The triggers write a change row inside the very statement that inserts
     * an item. Doctrine reads the new row's id back from the session's last
     * sequence value, so the change row must never draw from a sequence, or
     * every new place would be handed another table's number.
     */
    public function testAnInsertStillReadsBackItsOwnId(): void
    {
        $this->db->executeStatement(
            "INSERT INTO item (letter, name, geom, country_code, state, source, source_ref, attributes, region_id, created_at, updated_at)
             VALUES ('B', 'Id readback tap', ST_GeomFromText('POINT(4.5 50.4)', 4326), 'BE', 'verified', 'manual', 'manual:id-readback',
                     CAST('{}' AS jsonb), :rid, now(), now())",
            ['rid' => $this->rid],
        );

        self::assertSame(
            (int) $this->db->fetchOne("SELECT id FROM item WHERE source_ref = 'manual:id-readback'"),
            (int) $this->db->lastInsertId(),
        );
    }

    /** A row that moves region changes both regions. */
    public function testMovingARowMovesBothRegions(): void
    {
        $before = $this->stamps->regionStamps();
        $this->db->executeStatement(
            'UPDATE item SET region_id = NULL WHERE id = (SELECT min(id) FROM item WHERE region_id = :rid)',
            ['rid' => $this->rid],
        );
        $after = $this->stamps->regionStamps();

        self::assertNotSame($before[$this->rid], $after[$this->rid]);
        self::assertNotSame($before[CatalogStamps::NO_REGION] ?? '', $after[CatalogStamps::NO_REGION]);
    }

    /**
     * A contributor's public name is printed on pins in every region, so it
     * moves every stamp. A login writes the same table and prints nothing.
     */
    public function testAPublicNameMovesEveryRegionAndALoginMovesNone(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $rider = (new User())->setEmail('stamps.rider@example.test')->setDisplayName('Stamps Rider');
        $rider->setPassword('x');
        $em->persist($rider);
        $em->flush();
        $user = (int) $rider->getId();
        $before = $this->stamps->regionStamps();

        $this->db->executeStatement('UPDATE users SET last_login_at = now() WHERE id = :id', ['id' => $user]);
        $this->db->executeStatement('UPDATE users SET display_name = display_name WHERE id = :id', ['id' => $user]);
        self::assertSame($before, $this->stamps->regionStamps(), 'nothing a pin prints changed');

        $this->db->executeStatement("UPDATE users SET display_name = display_name || '.' WHERE id = :id", ['id' => $user]);
        $after = $this->stamps->regionStamps();
        foreach ($before as $rid => $stamp) {
            self::assertNotSame($stamp, $after[$rid], 'region '.$rid.' prints the name too');
        }
    }

    /**
     * A link is withheld from every payload once it is unsafe
     * (LinkVerdictStore::withhold()). The checker rewrites every verdict it
     * rechecks; only a link crossing into or out of unsafe counts.
     */
    public function testOnlyALinkCrossingUnsafeMovesTheStamps(): void
    {
        $before = $this->stamps->regionStamps();
        $this->db->executeStatement("INSERT INTO link_verdict (url_hash, url, verdict, checked_at) VALUES ('h1', 'https://example.org/a', 'ok', now())");
        $this->db->executeStatement("UPDATE link_verdict SET verdict = 'ok', checked_at = now() WHERE url_hash = 'h1'");
        self::assertSame($before, $this->stamps->regionStamps(), 'a safe link is printed as it was');

        $this->db->executeStatement("UPDATE link_verdict SET verdict = 'unsafe' WHERE url_hash = 'h1'");
        $unsafe = $this->stamps->regionStamps();
        self::assertNotSame($before[$this->rid], $unsafe[$this->rid], 'an unsafe link leaves every payload');

        $this->db->executeStatement("UPDATE link_verdict SET verdict = 'unsafe', checked_at = now() WHERE url_hash = 'h1'");
        self::assertSame($unsafe, $this->stamps->regionStamps(), 'a recheck that finds it unsafe again changes nothing');
    }

    /** Folding the change rows keeps every sum, so no rider refetches anything for it. */
    public function testCompactingKeepsEveryStamp(): void
    {
        $this->db->executeStatement("UPDATE item SET name = name || '.' WHERE region_id = :rid", ['rid' => $this->rid]);
        $this->db->executeStatement("UPDATE item SET name = name || '.' WHERE region_id = :rid", ['rid' => $this->rid]);
        $before = $this->stamps->regionStamps();

        self::assertGreaterThan(0, $this->stamps->compact(), 'there were rows to fold');
        self::assertSame($before, $this->stamps->regionStamps());
        self::assertSame(
            (int) $this->db->fetchOne('SELECT count(DISTINCT region_id) FROM catalog_change'),
            (int) $this->db->fetchOne('SELECT count(*) FROM catalog_change'),
            'one row per region after a fold',
        );
    }

    /**
     * The worldwide URL follows the rows no region holds and nothing inside a
     * region (owner 2026-09-16, "the token must be region bound"); its cache
     * key on the server follows everything, because its bytes do.
     */
    public function testTheWorldwideTagIsRegionBoundAndItsServerKeyIsNot(): void
    {
        $tag = $this->stamps->versionTag();
        $key = $this->stamps->worldKey();

        $this->db->executeStatement("UPDATE item SET name = name || '.' WHERE id = (SELECT min(id) FROM item WHERE region_id = :rid)", ['rid' => $this->rid]);

        self::assertSame($tag, $this->stamps->versionTag());
        self::assertNotSame($key, $this->stamps->worldKey());
    }
}
