<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\Item;
use App\Catalog\Entity\Submission;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\SubmissionType;
use App\Entity\User;
use App\Moderation\ModerationScope;
use App\Moderation\SubmissionQueue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A curator reviewing a NEW item sees what the item will be.
 *
 * There is nothing to diff a new item against, so the item IS the proposal: it
 * waits in state `submitted` holding exactly what the rider asked for. Reading
 * the item rather than the change map also survives a revision, which re-diffs
 * against the item and so records nothing for the fields it already carries.
 *
 * That is not hypothetical. A rider filed a road with a surface, a road type, a
 * smoothness and a traffic level, then went back and only lengthened the
 * stretch. The revision recorded `segment` alone, replaced the map, and left
 * the curator a shape and no values at all (owner-reported 2026-08-31).
 * `CatalogContributionService::mergeChanges()` stops that happening again; this
 * is what shows the submissions it already happened to, without touching their
 * rows.
 */
final class NewItemShowsItsValuesTest extends WebTestCase
{
    public function testANewItemsProposedValuesReachTheCuratorEvenWhenTheDiffIsEmpty(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $queue = static::getContainer()->get(SubmissionQueue::class);

        $rider = (new User())->setEmail('newitem-values@test.test');
        $rider->setPassword('x');
        $em->persist($rider);

        $item = (new Item())->setLetter('A')->setName('Zuiderdracht')
            ->setGeom('{"type":"LineString","coordinates":[[5.1076,52.6637],[5.1201,52.6503]]}')
            ->setCountryCode('NL')
            ->setState(ItemState::Submitted)->setSource(ItemSource::Osm)->setSourceRef('way/999042')
            ->setAttributes([
                'surface' => 'Asphalt',
                'roadType' => 'Cycleway',
                'smoothness' => 'Bad',
                'traffic' => 'Car-free',
                // A shape, which is reviewed on the map and never as text.
                'segment' => ['a' => [5.1076, 52.6637], 'b' => [5.1201, 52.6503], 'line' => [[5.1076, 52.6637]]],
            ]);
        $em->persist($item);
        $em->flush();

        // Exactly the shape a revised submission is left in: the shape only.
        $sub = (new Submission())
            ->setType(SubmissionType::NewItem)->setLetter('A')->setUserId((int) $rider->getId())
            ->setItemId((int) $item->getId())
            ->setTitle('Zuiderdracht')
            ->setGeom('{"type":"Point","coordinates":[5.1076,52.6637]}')
            ->setCountryCode('NL')
            ->setChanges(['segment' => ['was' => null, 'now' => ['a' => [5.1076, 52.6637]]]])
            ->setPayload([]);
        $em->persist($sub);
        $em->flush();

        $card = null;
        foreach ($queue->pendingForMap(ModerationScope::global()) as $row) {
            if ((int) $row['id'] === (int) $sub->getId()) {
                $card = $row;
                break;
            }
        }
        self::assertNotNull($card, 'the submission must reach the desk');

        $keys = array_column($card['changes'], 'key');
        foreach (['surface', 'roadType', 'smoothness', 'traffic'] as $field) {
            self::assertContains($field, $keys, "the curator must see the proposed {$field}");
        }
        self::assertNotContains('segment', $keys, 'a shape is reviewed on the map, never as text');

        $bySurface = array_values(array_filter($card['changes'], static fn (array $c): bool => 'surface' === $c['key']));
        self::assertSame('Asphalt', $bySurface[0]['now']);
        self::assertNull($bySurface[0]['was'], 'a new item proposes from nothing');
    }
}
