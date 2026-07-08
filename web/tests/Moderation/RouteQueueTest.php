<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Moderation\RouteQueue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RouteQueueTest extends KernelTestCase
{
    public function testPendingListsSubmittedRoutesOldestFirstWithCapContext(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        foreach (['A', 'B'] as $i => $tag) {
            $r = (new RecommendedRoute())->setName('Proposal '.$tag)
                ->setGeom('{"type":"LineString","coordinates":[[5.2,50.4],[5.3,50.5]]}')
                ->setDistanceM(10000 + $i)->setState(ItemState::Submitted)
                ->setSource(ItemSource::User)->setSourceRef('user:q-'.$tag)->setRegionId(5)->setProposedBy(7);
            $em->persist($r);
            $em->flush(); // distinct created_at ordering
        }

        $queue = static::getContainer()->get(RouteQueue::class);
        $rows = $queue->pending(null);

        self::assertGreaterThanOrEqual(2, \count($rows));
        self::assertSame('Proposal A', $rows[0]['name'], 'oldest first');
        self::assertArrayHasKey('activeInRegion', $rows[0]);
        self::assertArrayHasKey('cap', $rows[0]);
    }
}
