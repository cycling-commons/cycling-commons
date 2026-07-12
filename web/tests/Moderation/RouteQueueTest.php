<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\Entity\RouteSuggestion;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\RouteSuggestionReason;
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

    public function testPendingSuggestionsIncludesSegmentCount(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $route = (new RecommendedRoute())->setName('Route with corrections')
            ->setGeom('{"type":"LineString","coordinates":[[5.2,50.4],[5.3,50.5]]}')
            ->setDistanceM(12000)->setState(ItemState::Submitted)
            ->setSource(ItemSource::User)->setSourceRef('user:seg-'.uniqid())->setRegionId(5)->setProposedBy(7);
        $em->persist($route);
        $em->flush();

        $withSegments = new RouteSuggestion(
            $route->getId(),
            9,
            RouteSuggestionReason::BrokenTrack,
            'note',
            [['start' => 0.1, 'end' => 0.2], ['start' => 0.4, 'end' => 0.5]],
        );
        $withoutSegments = new RouteSuggestion($route->getId(), 9, RouteSuggestionReason::Other, null);
        $em->persist($withSegments);
        $em->persist($withoutSegments);
        $em->flush();

        $queue = static::getContainer()->get(RouteQueue::class);
        $rows = $queue->pendingSuggestions(null);

        $withSegmentsRow = self::findRowById($rows, $withSegments->getId());
        $withoutSegmentsRow = self::findRowById($rows, $withoutSegments->getId());

        self::assertNotNull($withSegmentsRow);
        self::assertNotNull($withoutSegmentsRow);
        self::assertSame(2, $withSegmentsRow['segmentCount']);
        self::assertSame(0, $withoutSegmentsRow['segmentCount']);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>|null
     */
    private static function findRowById(array $rows, ?int $id): ?array
    {
        foreach ($rows as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }
}
