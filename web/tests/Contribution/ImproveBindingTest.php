<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Contribution;

use App\Catalog\Entity\Item;
use App\Catalog\Entity\Submission;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\SubmissionType;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

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
}
