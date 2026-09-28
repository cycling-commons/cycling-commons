<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\CatalogDocuments;
use App\Catalog\CatalogProvider;
use App\Catalog\CatalogStamps;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A catalog document is built once per version and then served from the app
 * cache, which both web hosts share (catalog-data-model.md §9.1).
 */
final class CatalogDocumentsTest extends KernelTestCase
{
    private Connection $db;
    private CatalogDocuments $documents;
    private CacheItemPoolInterface $cache;
    private int $rid;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $this->documents = static::getContainer()->get(CatalogDocuments::class);
        $this->cache = static::getContainer()->get('cache.app');

        $src = __DIR__.'/../fixtures/catalog';
        $dir = sys_get_temp_dir().'/catalog-documents-'.getmypid();
        @mkdir($dir, 0777, true);
        foreach (['region-square.geojson', 'services.json'] as $f) {
            copy($src.'/'.$f, $dir.'/'.$f);
        }
        $tester = new CommandTester((new Application(self::$kernel))->find('app:catalog:import'));
        $tester->execute(['dir' => $dir]);
        $tester->assertCommandIsSuccessful();
        $this->db->executeStatement("UPDATE item SET state = 'verified' WHERE letter = 'D'");

        $this->rid = (int) $this->db->fetchOne("SELECT region_id FROM item WHERE region_id IS NOT NULL AND letter = 'D' ORDER BY id LIMIT 1");
    }

    /** The same bytes as a fresh build, under the version the stamps name. */
    public function testARegionDocumentIsTheProvidersBytes(): void
    {
        $doc = $this->documents->region($this->rid);

        self::assertSame(static::getContainer()->get(CatalogProvider::class)->json($this->rid), $doc['json']);
        self::assertSame(md5($doc['json']), $doc['etag']);
        $entry = $this->cache->getItem('catalog.doc.region.'.$this->rid)->get();
        self::assertIsArray($entry);
        self::assertSame(static::getContainer()->get(CatalogStamps::class)->stamp($this->rid), $entry['version']);
    }

    /**
     * While the version holds, the cache answers and the database is not
     * asked for the rows again. Proven by planting a marker in the entry: a
     * rebuild would have replaced it.
     */
    public function testTheCacheAnswersUntilTheRegionChanges(): void
    {
        $this->documents->region($this->rid);
        $item = $this->cache->getItem('catalog.doc.region.'.$this->rid);
        /** @var array{version: string, json: string, etag: string} $entry */
        $entry = $item->get();
        $this->cache->save($item->set(['json' => '{"marker":1}', 'etag' => 'marker'] + $entry));

        self::assertSame('{"marker":1}', $this->documents->region($this->rid)['json'], 'served from the cache');

        $this->db->executeStatement("UPDATE item SET name = name || '.' WHERE id = (SELECT min(id) FROM item WHERE region_id = :rid)", ['rid' => $this->rid]);

        $rebuilt = $this->documents->region($this->rid);
        self::assertStringNotContainsString('marker', $rebuilt['json'], 'an edit in the region rebuilds it');
        self::assertStringContainsString('"rid":'.$this->rid, $rebuilt['json']);
    }

    /** A region id nobody holds rows in is answered, empty, and never kept. */
    public function testAnUnknownRegionIsNotKept(): void
    {
        $unknown = $this->rid + 1000000;
        $doc = $this->documents->region($unknown);

        self::assertStringContainsString('"rid":'.$unknown, $doc['json']);
        self::assertFalse($this->cache->getItem('catalog.doc.region.'.$unknown)->isHit(), 'counting upwards must not fill the cache');
    }

    /** An edit in one region leaves another region's entry valid, and the worldwide one not. */
    public function testAnEditRebuildsItsRegionAndTheWorldOnly(): void
    {
        $other = CatalogStamps::NO_REGION;
        $this->documents->region($other);
        $world = $this->documents->worldwide();
        $otherItem = $this->cache->getItem('catalog.doc.region.'.$other);
        /** @var array{version: string, json: string, etag: string} $otherEntry */
        $otherEntry = $otherItem->get();
        $this->cache->save($otherItem->set(['json' => '{"marker":1}'] + $otherEntry));

        $this->db->executeStatement("UPDATE item SET name = name || '.' WHERE id = (SELECT min(id) FROM item WHERE region_id = :rid)", ['rid' => $this->rid]);

        self::assertSame('{"marker":1}', $this->documents->region($other)['json'], 'another region keeps its copy');
        self::assertNotSame($world['etag'], $this->documents->worldwide()['etag'], 'the worldwide document holds the edit');
    }
}
