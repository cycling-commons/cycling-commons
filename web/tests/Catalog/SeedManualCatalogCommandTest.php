<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\CatalogFormRegistry;
use App\Catalog\CatalogProvider;
use App\Catalog\Entity\Item;
use App\Catalog\FieldKind;
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
        // Hazards (F) now have a real serving path (map-and-search.md §4.5
        // Task A), so the retired Hautes Fagnes crosswind demo returns as one
        // seeded manual F row: 23 -> 24. The 2026-08-06 Swiss rollout then
        // added six alpine passes, all letter B: 24 -> 30.
        self::assertCount(30, $items, 'expected exactly the hand-authored demo pins (incl. the F hazard)');

        $byLetter = [];
        foreach ($items as $item) {
            self::assertSame(ItemState::Unverified, $item->getState(), $item->getName().' must seed as unverified, never verified');
            self::assertStringStartsWith('manual:', $item->getSourceRef());
            $byLetter[$item->getLetter()] = ($byLetter[$item->getLetter()] ?? 0) + 1;
        }
        ksort($byLetter);
        self::assertSame(
            ['B' => 11, 'C' => 5, 'D' => 4, 'E' => 1, 'F' => 1, 'G' => 1, 'H' => 4, 'I' => 2, 'J' => 1],
            $byLetter,
        );

        $crosswind = $this->em->getRepository(Item::class)->findOneBy(['sourceRef' => 'manual:crosswind-hautes-fagnes']);
        self::assertNotNull($crosswind);
        self::assertSame('F', $crosswind->getLetter());
        self::assertSame('Crosswind / fog', $crosswind->getAttributes()['hazardType']);

        $redoute = $this->em->getRepository(Item::class)->findOneBy(['sourceRef' => 'manual:cote-de-la-redoute']);
        self::assertNotNull($redoute);
        self::assertSame('Côte de la Redoute', $redoute->getName());
        self::assertSame('B', $redoute->getLetter());
        self::assertSame('Tough', $redoute->getAttributes()['effort']);
    }

    public function testSeededSelectValuesAreValidRegistryChoices(): void
    {
        // finding 21: a typo'd choice in the seed (e.g. severity 'Modrate') would
        // seed, serve and render untranslated, silently. Pin EVERY seeded manual
        // item's select/multiselect attribute values to the CatalogFormRegistry
        // choices for its letter — the hazard's hazardType/severity/worstWhen
        // included, but the whole seed set for free.
        $this->runSeed()->assertCommandIsSuccessful();
        $registry = static::getContainer()->get(CatalogFormRegistry::class);

        // letter -> [selectFieldName => choices[]] for every select/multiselect field.
        $choicesByLetter = [];
        foreach (ItemType::cases() as $type) {
            foreach ($registry->for($type)->all() as $field) {
                if (\in_array($field->kind, [FieldKind::Select, FieldKind::MultiSelect], true)) {
                    $choicesByLetter[$type->letter()][$field->name] = $field->choices;
                }
            }
        }

        $checked = 0;
        foreach ($this->em->getRepository(Item::class)->findBy(['source' => ItemSource::Manual]) as $item) {
            $fieldChoices = $choicesByLetter[$item->getLetter()] ?? [];
            foreach ($item->getAttributes() as $key => $value) {
                if (!isset($fieldChoices[$key])) {
                    continue;   // not a registry select field (free text, extra, display key)
                }
                foreach ((array) $value as $v) {
                    self::assertContains($v, $fieldChoices[$key], sprintf(
                        '%s (%s): attribute %s=%s is not a valid registry choice',
                        $item->getName(), $item->getLetter(), $key, (string) $v));
                    ++$checked;
                }
            }
        }
        self::assertGreaterThan(0, $checked, 'the seed must exercise at least one select field');
    }

    /**
     * C5 data-loss fix: the original C3-T9 seeder dropped the climb LINE
     * (`route`), the gradient profile (`grad`), the steepest-ramp marker
     * (`steep`) and collapsed each demo climb's `photos` (plural) gallery
     * down to nothing. Confirms Mur de Huy — id 11001 on the real dev DB,
     * the exact pin the user reported as broken — carries all four back,
     * verbatim from the pre-migration map.js CATALOG (git 8bae43d^).
     */
    public function testMurDeHuyCarriesRouteGradSteepAndBothPhotos(): void
    {
        $this->runSeed()->assertCommandIsSuccessful();

        $murDeHuy = $this->em->getRepository(Item::class)->findOneBy(['sourceRef' => 'manual:mur-de-huy']);
        self::assertNotNull($murDeHuy);
        $attrs = $murDeHuy->getAttributes();

        self::assertArrayHasKey('route', $attrs);
        self::assertCount(35, $attrs['route'], 'the full GPS track, not a truncated stub');
        self::assertSame([50.51656, 5.24049], $attrs['route'][0]);

        self::assertSame([6, 9, 13, 17, 21, 26, 23, 16, 11, 9, 8], $attrs['grad']);

        self::assertSame(['at' => [50.51765, 5.24788], 'pct' => '26%'], $attrs['steep']);

        self::assertArrayHasKey('photos', $attrs);
        self::assertCount(2, $attrs['photos'], 'both demo photos, not just one');
        self::assertSame('Hoebele', $attrs['photos'][0]['credit']);
        self::assertSame('Rz98', $attrs['photos'][1]['credit']);
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

        self::assertSame(30, $countAfterFirst);
        self::assertSame($countAfterFirst, $countAfterSecond, 're-running must not duplicate rows');
    }

    /**
     * A re-seed replaces the attributes JSON, but the measured climb values
     * in it come from `app:climbs:recompute --write`, not from the seed - a
     * re-seed on 2026-08-09 silently unmeasured four of the six Swiss passes.
     * While the seeded `route` line is unchanged, ItemUpsert::MEASURED_KEYS
     * must survive; seed-owned keys still refresh.
     */
    public function testReseedKeepsMeasuredValuesWhileTheLineIsUnchanged(): void
    {
        $this->runSeed()->assertCommandIsSuccessful();

        $furka = $this->em->getRepository(Item::class)->findOneBy(['sourceRef' => 'manual:furka-pass']);
        self::assertNotNull($furka);
        $attrs = $furka->getAttributes();
        self::assertArrayNotHasKey('length', $attrs, 'the seed itself must not type numbers');
        $attrs['length'] = 10597.0;
        $attrs['gain'] = 648.0;
        $attrs['avgGradient'] = '6.4%';
        $attrs['lineGrad'] = [5, 6, 7];
        $attrs['demSource'] = 'Copernicus DEM GLO-30';
        $furka->setAttributes($attrs);
        $this->em->flush();
        $this->em->clear();

        $this->runSeed()->assertCommandIsSuccessful();

        $furka = $this->em->getRepository(Item::class)->findOneBy(['sourceRef' => 'manual:furka-pass']);
        self::assertNotNull($furka);
        $attrs = $furka->getAttributes();
        self::assertSame(10597.0, $attrs['length'], 'measured length must survive a re-seed');
        self::assertSame(648.0, $attrs['gain']);
        self::assertSame('6.4%', $attrs['avgGradient']);
        self::assertSame([5, 6, 7], $attrs['lineGrad']);
        self::assertSame('Copernicus DEM GLO-30', $attrs['demSource']);
        self::assertArrayHasKey('route', $attrs, 'seed-owned keys still come from the seed');
    }

    /**
     * The other half of the same rule: a measurement is a claim about ONE
     * line. When the stored route no longer matches the seeded route (the
     * seed definition was redrawn), carrying the numbers over would be the
     * Roche-aux-Faucons failure - stored figures measured off a line that
     * moved. They must drop, and the command must say which climbs are left
     * unmeasured so the recompute is never forgotten silently.
     */
    public function testReseedDropsMeasuredValuesWhenTheLineChanged(): void
    {
        $this->runSeed()->assertCommandIsSuccessful();

        $furka = $this->em->getRepository(Item::class)->findOneBy(['sourceRef' => 'manual:furka-pass']);
        self::assertNotNull($furka);
        $attrs = $furka->getAttributes();
        $attrs['route'] = [[46.5, 8.4], [46.6, 8.5]]; // measured off a different line than the seed now defines
        $attrs['length'] = 12345.0;
        $attrs['lineGrad'] = [9, 9];
        $furka->setAttributes($attrs);
        $this->em->flush();
        $this->em->clear();

        $tester = $this->runSeed();
        $tester->assertCommandIsSuccessful();

        $furka = $this->em->getRepository(Item::class)->findOneBy(['sourceRef' => 'manual:furka-pass']);
        self::assertNotNull($furka);
        $attrs = $furka->getAttributes();
        self::assertArrayNotHasKey('length', $attrs, 'numbers measured off a replaced line must not survive');
        self::assertArrayNotHasKey('lineGrad', $attrs);
        self::assertNotSame([[46.5, 8.4], [46.6, 8.5]], $attrs['route'], 'the seed line wins');

        $display = $tester->getDisplay();
        self::assertStringContainsString('recompute', $display, 'the seeder must name the follow-up command');
        self::assertStringContainsString('Furka Pass', $display, 'the unmeasured climb must be named');
    }

    /**
     * C4-T11 dedup fix: items 11018/11019/11021 ("Abri Jean Poumay",
     * "Belvédère de la Hoëgne", "Signal de Botrange") duplicated existing OSM
     * rows and double/triple-rendered on the map. The seeder must skip any
     * manual pin whose (name, letter) matches an existing non-manual item.
     */
    public function testSkipsAManualPinThatDuplicatesAnExistingNonManualItem(): void
    {
        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement(
            "INSERT INTO item (letter, name, geom, country_code, state, source, source_ref, attributes, created_at, updated_at, imported_at)
             VALUES ('H', 'Abri Jean Poumay', ST_SetSRID(ST_MakePoint(5.8521, 50.5074), 4326), 'BE', 'verified', 'osm', 'osm:node:1', '{}', NOW(), NOW(), NOW())",
        );

        $tester = $this->runSeed();
        $tester->assertCommandIsSuccessful();

        $manualAbri = $this->em->getRepository(Item::class)->findOneBy(['sourceRef' => 'manual:abri-jean-poumay']);
        self::assertNull($manualAbri, 'a manual pin duplicating an existing non-manual (name, letter) item must not be seeded');

        // Every other manual pin still seeds normally (29, not 30 — Abri Jean Poumay skipped).
        $items = $this->em->getRepository(Item::class)->findBy(['source' => ItemSource::Manual]);
        self::assertCount(29, $items);

        self::assertStringContainsString('Abri Jean Poumay', $tester->getDisplay());
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
