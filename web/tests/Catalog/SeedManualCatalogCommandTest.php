<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\CatalogProvider;
use App\Catalog\Entity\Item;
use App\Catalog\Import\AttributeVocabulary;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\ItemType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * C3-T9 (spec §W3): the hand-authored demo pins baked into map.js's CATALOG
 * array get a real `source = manual`, `state = unverified` item row each —
 * a seeded pin is a rider contribution, not a pre-verified fact.
 */
final class SeedManualCatalogCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    private function runSeed(): CommandTester
    {
        $app = new Application(self::$kernel);
        $tester = new CommandTester($app->find('app:catalog:seed-manual'));
        $tester->execute([]);

        return $tester;
    }

    public function testSeedsExpectedManualUnverifiedItemsPerLetter(): void
    {
        $tester = $this->runSeed();
        $tester->assertCommandIsSuccessful();

        $items = $this->em->getRepository(Item::class)->findBy(['source' => ItemSource::Manual]);
        // C3-T10: letter F is deliberately skipped (no serving path for hazards yet),
        // so 24 -> 23; its one demo pin stays hardcoded in map.js.
        self::assertCount(23, $items, 'expected exactly the ~23 hand-authored demo pins (F excluded)');

        $byLetter = [];
        foreach ($items as $item) {
            self::assertSame(ItemState::Unverified, $item->getState(), $item->getName().' must seed as unverified, never verified');
            self::assertStringStartsWith('manual:', $item->getSourceRef());
            $byLetter[$item->getLetter()] = ($byLetter[$item->getLetter()] ?? 0) + 1;
        }
        ksort($byLetter);
        self::assertSame(
            ['B' => 5, 'C' => 5, 'D' => 4, 'E' => 1, 'G' => 1, 'H' => 4, 'I' => 2, 'J' => 1],
            $byLetter,
        );

        $redoute = $this->em->getRepository(Item::class)->findOneBy(['sourceRef' => 'manual:cote-de-la-redoute']);
        self::assertNotNull($redoute);
        self::assertSame('Côte de la Redoute', $redoute->getName());
        self::assertSame('B', $redoute->getLetter());
        self::assertSame('Tough', $redoute->getAttributes()['effort']);
    }

    public function testEverySeededItemValidatesAgainstItsVocabulary(): void
    {
        $this->runSeed()->assertCommandIsSuccessful();
        $vocabulary = static::getContainer()->get(AttributeVocabulary::class);

        $items = $this->em->getRepository(Item::class)->findBy(['source' => ItemSource::Manual]);
        self::assertNotEmpty($items);
        foreach ($items as $item) {
            $type = ItemType::fromParam($item->getLetter());
            // Throws \InvalidArgumentException (failing the test) on any unknown key.
            $vocabulary->assertValid($type, $item->getAttributes());
        }
    }

    public function testRunningTwiceIsIdempotent(): void
    {
        $this->runSeed()->assertCommandIsSuccessful();
        $countAfterFirst = \count($this->em->getRepository(Item::class)->findBy(['source' => ItemSource::Manual]));

        $this->em->clear();
        $this->runSeed()->assertCommandIsSuccessful();
        $countAfterSecond = \count($this->em->getRepository(Item::class)->findBy(['source' => ItemSource::Manual]));

        self::assertSame(23, $countAfterFirst);
        self::assertSame($countAfterFirst, $countAfterSecond, 're-running must not duplicate rows');
    }

    public function testSeededManualLetterEStayIsServedByCatalogProvider(): void
    {
        $this->runSeed()->assertCommandIsSuccessful();

        $payload = static::getContainer()->get(CatalogProvider::class)->payload();
        $names = array_map(
            static fn (array $f): ?string => $f['properties']['n'] ?? null,
            $payload['E']['osm']['features'],
        );
        self::assertContains(
            'Cyclist-friendly gîte · Amblève valley',
            $names,
            'a manual (source=manual) letter-E stay must be served in the osm bucket, not dropped by the pivot split',
        );

        $gite = array_values(array_filter(
            $payload['E']['osm']['features'],
            static fn (array $f): bool => 'Cyclist-friendly gîte · Amblève valley' === ($f['properties']['n'] ?? null),
        ))[0];
        self::assertSame('manual', $gite['properties']['srcType']);
        self::assertIsInt($gite['properties']['id']);
    }
}
