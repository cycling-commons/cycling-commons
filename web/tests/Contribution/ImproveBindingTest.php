<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Contribution;

use App\Catalog\Entity\ChangeHistory;
use App\Catalog\Entity\Item;
use App\Catalog\Entity\Submission;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\SubmissionType;
use App\Entity\User;
use App\Moderation\ModerationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The edit flow: `/improve?item=<dbId>` binds a real Item, prefills the
 * registry-driven form with its current name + attributes, and on submit
 * persists an Edit submission whose `changes` holds only genuinely-changed
 * fields (was/now). No item id -> an "unbound" explainer, never a fake
 * default editor (spec §6, §8).
 *
 * Field-name note: letter D is BikeServices ({@see \App\Catalog\ItemType}).
 * Its real registry fields ({@see \App\Catalog\CatalogFormRegistry::for()})
 * are: name, pumpValve, openingHours, tools, correction (fix) and workStand,
 * chainTool, ebikeCharging (add-missing) — not the `t`/`hours` names an
 * earlier draft of this test assumed. `tools` (free text) stands in for the
 * "prefilled with a plain value" assertion; `openingHours` (default '24/7')
 * stands in for the was/now snapshot assertion.
 */
final class ImproveBindingTest extends WebTestCase
{
    public function testImproveFormIsPrefilledFromTheRealItem(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $item = (new Item())->setLetter('D')->setName('Repair station · Malmedy')
            ->setGeom('{"type":"Point","coordinates":[6.027,50.426]}')->setCountryCode('BE')
            ->setState(ItemState::Unverified)->setSource(ItemSource::Osm)->setSourceRef('node/999001')
            ->setAttributes(['tools' => 'Repair station']);
        $em->persist($item);

        $user = (new User())->setEmail('improver@test.test');
        $user->setPassword('x');
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/improve?item='.$item->getId());

        self::assertResponseIsSuccessful();
        // Edit-bridge acceptance (spec §8): current name visible, and the
        // registry field holding 'tools' is prefilled with the item's value.
        self::assertStringContainsString('Repair station · Malmedy', (string) $client->getResponse()->getContent());
        self::assertGreaterThan(0, $crawler->filter('input[value="Repair station"], option[selected][value="Repair station"]')->count());
    }

    /**
     * C2-T6 (spec §W2): the climb drawer shows effort/famousFor/approach
     * (C2-T5) plus road quality (sq) and traffic (tr) — this task makes sq/tr
     * registry-declared selects too (CatalogFormRegistry::for(Climbs)), so
     * /improve?item=<id> must prefill all five for a bound climb.
     */
    public function testImproveFormForClimbExposesEffortRoadQualityTrafficFamousForAndApproach(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $item = (new Item())->setLetter('B')->setName('Côte de Test Reconciliation')
            ->setGeom('{"type":"Point","coordinates":[5.699,50.492]}')->setCountryCode('BE')
            ->setState(ItemState::Unverified)->setSource(ItemSource::Osm)->setSourceRef('node/999003')
            ->setAttributes([
                'effort' => 'Tough',
                'famousFor' => 'La Flèche Wallonne summit finish',
                'approach' => 'From Sougné-Remouchamps (Aywaille)',
                'sq' => 'Good',
                'tr' => 'Quiet',
            ]);
        $em->persist($item);

        $user = (new User())->setEmail('improver-climb@test.test');
        $user->setPassword('x');
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/improve?item='.$item->getId());

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('option[selected][value="Tough"]')->count(), 'effort prefilled');
        self::assertGreaterThan(0, $crawler->filter('option[selected][value="Good"]')->count(), 'sq (road quality) prefilled');
        self::assertGreaterThan(0, $crawler->filter('option[selected][value="Quiet"]')->count(), 'tr (traffic) prefilled');
        self::assertGreaterThan(0, $crawler->filter('input[value="La Flèche Wallonne summit finish"]')->count(), 'famousFor prefilled');
        self::assertGreaterThan(0, $crawler->filter('input[value="From Sougné-Remouchamps (Aywaille)"]')->count(), 'approach prefilled');
    }

    /**
     * The reported bug: an imported climb (Wallonia harvest, source=wikidata)
     * bakes its displayed Average/Max gradient, Surface and Famous-for values
     * as pre-formatted strings inside a `record` array — the drawer renders
     * `record` directly so it shows them fine, but before
     * {@see \App\Catalog\Command\BackfillAttributesCommand} ran, the improve
     * FORM (which prefills from discrete `attributes.<key>`) rendered those
     * four fields blank. This is the exact baked shape confirmed on the real
     * dev-DB item ("Côte de Bohissau", id 3132): no discrete avgGradient/
     * maxGradient/surface/famousFor keys, only `record`. Running the backfill
     * command (as `make`/ops would against a real DB) must make
     * `/improve?item=<id>` prefill all four — the real acceptance criterion,
     * not just "the command wrote some attributes".
     */
    public function testImproveFormPrefillsGradientSurfaceAndFamousForBackfilledFromABakedRecord(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $item = (new Item())->setLetter('B')->setName('Côte de Bohissau')
            ->setGeom('{"type":"Point","coordinates":[5.11182,50.49479]}')->setCountryCode('BE')
            ->setState(ItemState::Unverified)->setSource(ItemSource::Wikidata)->setSourceRef('wikidata:Q3430429-test')
            ->setAttributes([
                'sq' => 'Good', 'tr' => 'Quiet', 'cur' => true,
                'record' => [
                    ['label' => 'Length', 'value' => '1.1 km'],
                    ['label' => 'Average gradient', 'value' => '5.7%'],
                    ['label' => 'Max gradient', 'value' => '~13% (steepest ramp)'],
                    ['label' => 'Famous for', 'value' => 'La Flèche Wallonne'],
                    ['label' => 'Surface', 'value' => 'Asphalt', 'method' => 'OSM'],
                ],
            ]);
        $em->persist($item);

        $user = (new User())->setEmail('improver-backfill@test.test');
        $user->setPassword('x');
        $em->persist($user);
        $em->flush();

        // Run the backfill against the TEST DB, exactly as ops would run it
        // against the real one — the acceptance criterion is the form after
        // the backfill, not a hand-seeded discrete attribute.
        $tester = new CommandTester((new Application(self::$kernel))->find('app:catalog:backfill-attributes'));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        $em->clear();

        $client->loginUser($em->find(User::class, $user->getId()));
        $crawler = $client->request('GET', '/improve?item='.$item->getId());

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('input[value="5.7"]')->count(), 'avgGradient prefilled from the baked record');
        self::assertGreaterThan(0, $crawler->filter('input[value="13"]')->count(), 'maxGradient prefilled from the baked record');
        self::assertGreaterThan(0, $crawler->filter('option[selected][value="Asphalt"]')->count(), 'surface prefilled from the baked record');
        self::assertGreaterThan(0, $crawler->filter('input[value="La Flèche Wallonne"]')->count(), 'famousFor prefilled from the baked record');
    }

    /**
     * C2-T7 (spec §W2): D · Bike services drawer reconciliation — pumpValve
     * now renders as a real drawer row (map.js's POI_ATTR_FIELDS.services),
     * so an approved edit must actually update the item and leave a
     * change_history row, not just prefill the form.
     */
    public function testEditRoundTripUpdatesBikeServicesPumpValveWithHistory(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $service = static::getContainer()->get(\App\Service\ContributionStubInterface::class);
        $moderation = static::getContainer()->get(ModerationService::class);

        $item = (new Item())->setLetter('D')->setName('Repair station · Reconciliation')
            ->setGeom('{"type":"Point","coordinates":[6.027,50.426]}')->setCountryCode('BE')
            ->setState(ItemState::Unverified)->setSource(ItemSource::Osm)->setSourceRef('node/999004')
            ->setAttributes(['pumpValve' => 'Presta only']);
        $user = (new User())->setEmail('improver-d@test.test');
        $user->setPassword('x');
        $em->persist($item);
        $em->persist($user);
        $em->flush();

        $receipt = $service->submit('improve', [
            '_item_id' => $item->getId(), 'type' => 'bike-services',
            'details' => ['pumpValve' => 'Presta + Schrader'],
            'extras' => [], 'lat' => '50.426', 'lng' => '6.027',
        ], $user);

        $curator = (new User())->setEmail('curator-d@test.test');
        $curator->setPassword('x');
        $em->persist($curator);
        $em->flush();

        $moderation->decide((int) $receipt->submissionId, 'approve', $curator, null);

        $em->refresh($item);
        self::assertSame('Presta + Schrader', $item->getAttributes()['pumpValve']);
        $history = $em->getRepository(ChangeHistory::class)->findOneBy(['itemId' => $item->getId(), 'field' => 'pumpValve']);
        self::assertNotNull($history);
        self::assertSame('Presta only', $history->getOldValue());
        self::assertSame('Presta + Schrader', $history->getNewValue());
    }

    /**
     * C2-T7 (spec §W2): E · Where to sleep — the registry's Website field is
     * now keyed 'web' (not 'website'), matching the key osmDrawer already
     * reads/renders and every OSM-harvested stay already carries. Confirms
     * both the prefill (regression for the rename) and the round-trip for
     * 'web' + the newly-drawer-visible 'bikeStorage'.
     */
    public function testEditRoundTripUpdatesStaysWebAndBikeStorageWithHistory(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $service = static::getContainer()->get(\App\Service\ContributionStubInterface::class);
        $moderation = static::getContainer()->get(ModerationService::class);

        $item = (new Item())->setLetter('E')->setName('Cyclist-friendly gîte · Test')
            ->setGeom('{"type":"Point","coordinates":[5.62,50.45]}')->setCountryCode('BE')
            ->setState(ItemState::Unverified)->setSource(ItemSource::Osm)->setSourceRef('node/999005')
            ->setAttributes(['web' => 'https://old.example.test', 'bikeStorage' => 'On request']);
        $user = (new User())->setEmail('improver-e@test.test');
        $user->setPassword('x');
        $em->persist($item);
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/improve?item='.$item->getId());
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('input[value="https://old.example.test"]')->count(), 'web prefilled under the renamed key');
        self::assertGreaterThan(0, $crawler->filter('option[selected][value="On request"]')->count(), 'bikeStorage prefilled');

        $receipt = $service->submit('improve', [
            '_item_id' => $item->getId(), 'type' => 'where-to-sleep',
            'details' => ['web' => 'https://new.example.test', 'bikeStorage' => 'Yes — locked room'],
            'extras' => [], 'lat' => '50.45', 'lng' => '5.62',
        ], $user);

        $curator = (new User())->setEmail('curator-e@test.test');
        $curator->setPassword('x');
        $em->persist($curator);
        $em->flush();

        $moderation->decide((int) $receipt->submissionId, 'approve', $curator, null);

        $em->refresh($item);
        self::assertSame('https://new.example.test', $item->getAttributes()['web']);
        self::assertSame('Yes — locked room', $item->getAttributes()['bikeStorage']);
        $history = $em->getRepository(ChangeHistory::class)->findBy(['itemId' => $item->getId()], ['field' => 'ASC']);
        self::assertCount(2, $history);
        self::assertSame(['bikeStorage', 'web'], [$history[0]->getField(), $history[1]->getField()]);
    }

    public function testUnboundImproveShowsExplainerNotForm(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('improver2@test.test');
        $user->setPassword('x');
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/improve');

        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('form[name="improve"]')->count());
        self::assertGreaterThan(0, $crawler->filter('[data-improve-unbound]')->count());
    }

    public function testEditSubmissionSnapshotsOnlyChangedFields(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $service = static::getContainer()->get(\App\Service\ContributionStubInterface::class);

        $item = (new Item())->setLetter('D')->setName('Repair station · Malmedy')
            ->setGeom('{"type":"Point","coordinates":[6.027,50.426]}')->setCountryCode('BE')
            ->setState(ItemState::Unverified)->setSource(ItemSource::Osm)->setSourceRef('node/999002')
            ->setAttributes(['tools' => 'Repair station', 'openingHours' => '24/7']);
        $user = (new User())->setEmail('improver3@test.test');
        $user->setPassword('x');
        $em->persist($item);
        $em->persist($user);
        $em->flush();

        $receipt = $service->submit('improve', [
            '_item_id' => $item->getId(), 'type' => 'bike-services',
            'details' => ['tools' => 'Repair station', 'openingHours' => 'closed Sundays'],
            'extras' => [], 'lat' => '50.426', 'lng' => '6.027',
        ], $user);

        $sub = $em->find(Submission::class, $receipt->submissionId);
        self::assertSame(SubmissionType::Edit, $sub->getType());
        self::assertSame($item->getId(), $sub->getItemId());
        self::assertArrayNotHasKey('tools', $sub->getChanges(), 'unchanged fields are not snapshotted');
        self::assertSame(['was' => '24/7', 'now' => 'closed Sundays'], $sub->getChanges()['openingHours']);
    }

    /**
     * 'name' is a pseudo-field (lives on Item::name, never in attributes —
     * see ModerationService::applyEdit). Before this fix, submitImprove
     * compared the proposed name against $item->getAttributes()['name']
     * (always null), so an unchanged name was wrongly snapshotted as
     * {was: null, now: <name>}. It must be compared against
     * $item->getName() instead, and a genuinely changed field must still
     * record the correct `was`.
     */
    public function testEditSubmissionSnapshotsNameOnlyWhenActuallyChanged(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $service = static::getContainer()->get(\App\Service\ContributionStubInterface::class);

        $item = (new Item())->setLetter('D')->setName('Repair station · Malmedy')
            ->setGeom('{"type":"Point","coordinates":[6.027,50.426]}')->setCountryCode('BE')
            ->setState(ItemState::Unverified)->setSource(ItemSource::Osm)->setSourceRef('node/999006')
            ->setAttributes(['tools' => 'Repair station']);
        $user = (new User())->setEmail('improver4@test.test');
        $user->setPassword('x');
        $em->persist($item);
        $em->persist($user);
        $em->flush();

        // Name resubmitted unchanged alongside a genuinely changed field.
        $receipt = $service->submit('improve', [
            '_item_id' => $item->getId(), 'type' => 'bike-services',
            'details' => ['name' => 'Repair station · Malmedy', 'tools' => 'Repair station + Allen keys'],
            'extras' => [], 'lat' => '50.426', 'lng' => '6.027',
        ], $user);

        $sub = $em->find(Submission::class, $receipt->submissionId);
        self::assertArrayNotHasKey('name', $sub->getChanges(), 'unchanged name is not snapshotted');
        self::assertSame(['was' => 'Repair station', 'now' => 'Repair station + Allen keys'], $sub->getChanges()['tools']);

        // A second submission with a genuinely changed name records the
        // correct `was` (the item's actual name, not null).
        $receipt2 = $service->submit('improve', [
            '_item_id' => $item->getId(), 'type' => 'bike-services',
            'details' => ['name' => 'Repair station · Malmedy (renamed)'],
            'extras' => [], 'lat' => '50.426', 'lng' => '6.027',
        ], $user);

        $sub2 = $em->find(Submission::class, $receipt2->submissionId);
        self::assertSame(
            ['was' => 'Repair station · Malmedy', 'now' => 'Repair station · Malmedy (renamed)'],
            $sub2->getChanges()['name'],
        );
    }
}
