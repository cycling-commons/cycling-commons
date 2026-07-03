<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\Entity\Item;
use App\Catalog\Entity\Region;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\World\Entity\Country;
use App\World\Entity\Subdivision;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ImportCatalogCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    private function runImport(string $dir): CommandTester
    {
        $app = new Application(self::$kernel);
        $tester = new CommandTester($app->find('app:catalog:import'));
        $tester->execute(['dir' => $dir]);

        return $tester;
    }

    private function fixturesDir(string $subset = 'ok'): string
    {
        // Copy only the wanted files into a temp dir so error-fixtures don't pollute the happy path.
        $src = __DIR__.'/../fixtures/catalog';
        $dir = sys_get_temp_dir().'/catalog-import-'.$subset.'-'.getmypid();
        @mkdir($dir, 0777, true);
        $files = 'ok' === $subset
            ? ['region-square.geojson', 'services.json', 'surface.json']
            : ['bad-key' === $subset ? 'bad-key.json' : 'bad-geom.json'];
        foreach ($files as $f) {
            copy($src.'/'.$f, $dir.'/'.$f);
        }

        return $dir;
    }

    public function testImportCreatesRegionItemsAndMembership(): void
    {
        $tester = $this->runImport($this->fixturesDir());
        $tester->assertCommandIsSuccessful();

        $region = $this->em->getRepository(Region::class)->findOneBy(['slug' => 'test-square']);
        self::assertNotNull($region);

        $items = $this->em->getRepository(Item::class)->findAll();
        self::assertCount(4, $items); // 3 services + 1 surface

        $shop = $this->em->getRepository(Item::class)->findOneBy(['sourceRef' => 'node/1001']);
        self::assertNotNull($shop);
        self::assertSame('D', $shop->getLetter());
        self::assertSame('Ecocyclo', $shop->getName());
        self::assertSame(ItemState::Unverified, $shop->getState());
        self::assertSame(ItemSource::Osm, $shop->getSource());
        self::assertSame('Bike shop', $shop->getAttributes()['t']);
        self::assertArrayNotHasKey('prov', $shop->getAttributes());
        self::assertArrayNotHasKey('ref', $shop->getAttributes());
        self::assertSame($region->getId(), $shop->getRegionId());     // inside the square
        self::assertSame('BE', $shop->getCountryCode());

        $outside = $this->em->getRepository(Item::class)->findOneBy(['sourceRef' => 'node/1003']);
        self::assertNotNull($outside);
        self::assertNull($outside->getRegionId());                    // outside the square

        $seg = $this->em->getRepository(Item::class)->findOneBy(['sourceRef' => 'way/2001']);
        self::assertNotNull($seg);
        self::assertSame('A', $seg->getLetter());
        self::assertSame($region->getId(), $seg->getRegionId());      // PointOnSurface of the line is inside
    }

    public function testImportTwiceIsIdempotent(): void
    {
        $dir = $this->fixturesDir();
        $this->runImport($dir)->assertCommandIsSuccessful();
        $countAfterFirst = \count($this->em->getRepository(Item::class)->findAll());
        $this->runImport($dir)->assertCommandIsSuccessful();
        self::assertSame($countAfterFirst, \count($this->em->getRepository(Item::class)->findAll()));
    }

    public function testUpdatePreservesState(): void
    {
        $dir = $this->fixturesDir();
        $this->runImport($dir)->assertCommandIsSuccessful();
        $shop = $this->em->getRepository(Item::class)->findOneBy(['sourceRef' => 'node/1001']);
        self::assertNotNull($shop);
        $shop->setState(ItemState::Verified);
        $this->em->flush();
        $this->em->clear();

        $this->runImport($dir)->assertCommandIsSuccessful();
        $shop = $this->em->getRepository(Item::class)->findOneBy(['sourceRef' => 'node/1001']);
        self::assertNotNull($shop);
        self::assertSame(ItemState::Verified, $shop->getState()); // upsert never touches state
    }

    public function testUnknownAttributeKeyFails(): void
    {
        $tester = $this->runImport($this->fixturesDir('bad-key'));
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('bogus', $tester->getDisplay());
    }

    public function testWrongGeometryKindFails(): void
    {
        $tester = $this->runImport($this->fixturesDir('bad-geom'));
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('LineString', $tester->getDisplay());
    }

    public function testSubdivisionResolvedWhenWorldDataPresent(): void
    {
        // The World bundle (app:world:import) seeds real reference data outside
        // any test transaction, so BE / BE-WBR may already exist in this DB —
        // reuse-or-create (same pattern as ImportWorldDataCommand) keeps this
        // test correct whether run against a fresh DB or an already-seeded one.
        $country = $this->em->getRepository(Country::class)->findOneBy(['iso2' => 'BE'])
            ?? (new Country())->setIso2('BE')->setIso3('BEL')->setName('Belgium');
        $this->em->persist($country);
        $sub = $this->em->getRepository(Subdivision::class)->findOneBy(['code' => 'BE-WBR'])
            ?? (new Subdivision())->setCode('BE-WBR')->setName('Brabant wallon')->setCountry($country);
        $this->em->persist($sub);
        $this->em->flush();

        $this->runImport($this->fixturesDir())->assertCommandIsSuccessful();
        $shop = $this->em->getRepository(Item::class)->findOneBy(['sourceRef' => 'node/1001']);
        self::assertNotNull($shop);
        self::assertSame($sub->getId(), $shop->getSubdivisionId()); // prov "Brabant wallon" -> BE-WBR
    }
}
