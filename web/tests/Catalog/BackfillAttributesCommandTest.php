<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\Entity\Item;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Real-data bug fix: imported climbs (Wallonia harvest, source=wikidata)
 * baked their displayed values as pre-formatted strings inside a `record`
 * array instead of also writing the discrete registry-keyed attributes the
 * edit FORM prefills from ({@see \App\Catalog\CatalogFormRegistry}) — so the
 * drawer showed Average/Max gradient, Surface and Famous for (it renders
 * `record` directly) but /improve?item=<id> rendered those four fields
 * blank. {@see \App\Catalog\Command\BackfillAttributesCommand} derives the
 * missing discrete attributes from `record`, once, without ever overwriting
 * a value that's already there.
 */
final class BackfillAttributesCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    private function runBackfill(): CommandTester
    {
        $app = new Application(self::$kernel);
        $tester = new CommandTester($app->find('app:catalog:backfill-attributes'));
        $tester->execute([]);

        return $tester;
    }

    /** @param array<string, mixed> $attributes */
    private function createClimb(array $attributes, string $name = 'Côte de Bohissau (test)'): Item
    {
        $item = (new Item())->setLetter('N')->setName($name)
            ->setGeom('{"type":"Point","coordinates":[5.11182,50.49479]}')->setCountryCode('BE')
            ->setState(ItemState::Unverified)->setSource(ItemSource::Wikidata)
            ->setSourceRef('wikidata:Q'.random_int(1, \PHP_INT_MAX))
            ->setAttributes($attributes);
        $this->em->persist($item);
        $this->em->flush();

        return $item;
    }

    /**
     * The exact baked shape confirmed on item 3132 ("Côte de Bohissau").
     *
     * @return list<array{label: string, value: string, method?: string}>
     */
    private function bakedRecord(): array
    {
        return [
            ['label' => 'Length', 'value' => '1.1 km'],
            ['label' => 'Average gradient', 'value' => '5.7%'],
            ['label' => 'Max gradient', 'value' => '~13% (steepest ramp)'],
            ['label' => 'Famous for', 'value' => 'La Flèche Wallonne'],
            ['label' => 'Surface', 'value' => 'Asphalt', 'method' => 'OSM'],
        ];
    }

    public function testBackfillsAvgGradientSurfaceAndFamousForFromBakedRecord(): void
    {
        $item = $this->createClimb([
            'sq' => 'Good', 'tr' => 'Quiet', 'cur' => true,
            'record' => $this->bakedRecord(),
        ]);

        $tester = $this->runBackfill();
        $tester->assertCommandIsSuccessful();

        $this->em->clear();
        $reloaded = $this->em->find(Item::class, $item->getId());
        $attrs = $reloaded->getAttributes();

        self::assertSame('5.7', $attrs['avgGradient'], 'strip the trailing % ');
        /* maxGradient is NOT backfilled from a baked record, and that is
           deliberate (2026-08-05).

           Our field is the **steepest sustained stretch**. A baked record's "Max gradient"
           is a POINT maximum from whoever compiled it — Mur de Huy's famous
           ~26% is its steepest hairpin, which is a different measurement over a
           different distance. Copying one into the other would put a foreign
           definition into a field whose whole purpose is that it means the same
           thing on every climb, which is the fault this rename exists to fix.

           A climb with a drawn line gets the real figure from
           `app:climbs:recompute`. A climb without one is better left empty than
           filled with a number measured some other way. */
        self::assertArrayNotHasKey('maxGradient', $attrs,
            'a point maximum must not be backfilled into the sustained-gradient field');
        self::assertSame('Asphalt', $attrs['surface'], 'exact registry choice match');
        self::assertSame('La Flèche Wallonne', $attrs['famousFor'], 'verbatim');
        // Untouched: record stays (it still holds the derived Length row) and
        // the pre-existing discrete keys are unaffected.
        self::assertSame('Good', $attrs['sq']);
        self::assertSame('Quiet', $attrs['tr']);
        self::assertArrayHasKey('record', $attrs);
        self::assertCount(5, $attrs['record']);

        self::assertStringContainsString('3 attribute(s)', $tester->getDisplay());
    }

    public function testRerunningIsIdempotent(): void
    {
        $item = $this->createClimb(['record' => $this->bakedRecord()]);

        $this->runBackfill()->assertCommandIsSuccessful();
        $this->em->clear();
        $afterFirst = $this->em->find(Item::class, $item->getId())->getAttributes();

        $secondRun = $this->runBackfill();
        $secondRun->assertCommandIsSuccessful();
        $this->em->clear();
        $afterSecond = $this->em->find(Item::class, $item->getId())->getAttributes();

        self::assertSame($afterFirst, $afterSecond, 're-running must not change already-backfilled attributes');
        self::assertStringContainsString('Backfilled 0 attribute(s)', $secondRun->getDisplay());
    }

    public function testDoesNotOverwriteAnAlreadySetDiscreteAttribute(): void
    {
        $item = $this->createClimb([
            // A real edit already set avgGradient to something that disagrees
            // with the stale baked record — the discrete value must win.
            'avgGradient' => '9.9',
            'record' => $this->bakedRecord(),
        ]);

        $this->runBackfill()->assertCommandIsSuccessful();

        $this->em->clear();
        $attrs = $this->em->find(Item::class, $item->getId())->getAttributes();

        self::assertSame('9.9', $attrs['avgGradient'], 'an existing discrete value is never overwritten');
        // The others that still map, and had no discrete value yet, backfill.
        // maxGradient is not among them — see the note above.
        self::assertSame('Asphalt', $attrs['surface']);
        self::assertSame('La Flèche Wallonne', $attrs['famousFor']);
    }

    public function testAClimbWithNoBakedRecordIsLeftAlone(): void
    {
        $item = $this->createClimb(['avgGradient' => '8.4', 'surface' => 'Asphalt']);

        $tester = $this->runBackfill();
        $tester->assertCommandIsSuccessful();

        $this->em->clear();
        $attrs = $this->em->find(Item::class, $item->getId())->getAttributes();
        self::assertEqualsCanonicalizing(['avgGradient' => '8.4', 'surface' => 'Asphalt'], $attrs);
    }

    public function testUnmatchedSurfaceValueIsLeftUnsetAndReported(): void
    {
        $item = $this->createClimb([
            'record' => [
                ['label' => 'Surface', 'value' => 'Dirt track (not a registry choice)'],
                ['label' => 'Famous for', 'value' => 'A local classic'],
            ],
        ]);

        $tester = $this->runBackfill();
        $tester->assertCommandIsSuccessful();

        $this->em->clear();
        $attrs = $this->em->find(Item::class, $item->getId())->getAttributes();
        self::assertArrayNotHasKey('surface', $attrs, 'no exact registry choice match — never guess');
        self::assertSame('A local classic', $attrs['famousFor']);
        // Console output may line-wrap the full note text — a short,
        // unwrapped fragment is a more robust assertion than the full phrase.
        self::assertStringContainsString('left unset', $tester->getDisplay());
    }
}
