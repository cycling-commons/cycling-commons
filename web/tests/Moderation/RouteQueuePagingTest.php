<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\Entity\Region;
use App\Catalog\Entity\RouteSuggestion;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\RouteSuggestionReason;
use App\Entity\User;
use App\Moderation\ModerationScope;
use App\Moderation\RouteQueue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The Routes desk carries TWO growing work streams on one pane — proposals and
 * corrections — so it carries two pagers, and each has to count the set it is
 * actually paging.
 *
 * The trap this guards is the desk's OTHER count: `total()` and
 * `pendingSuggestionCount()` feed the tab badge and deliberately ignore the
 * region filter, because a curator who has narrowed to one region should still
 * see how much work the whole scope holds. Paging off those numbers would tell
 * a filtered desk it had four pages of one region's work.
 *
 * @see docs/specs/route-domain.md §5
 */
final class RouteQueuePagingTest extends WebTestCase
{
    private function proposer(EntityManagerInterface $em): int
    {
        $u = (new User())->setEmail('rq-paging-'.uniqid('', true).'@test.test');
        $u->setPassword('x');
        $em->persist($u);
        $em->flush();

        return (int) $u->getId();
    }

    private function submitted(EntityManagerInterface $em, string $name, ?int $regionId): RecommendedRoute
    {
        $r = (new RecommendedRoute())->setName($name)
            ->setGeom('{"type":"LineString","coordinates":[[5.2,50.4],[5.3,50.5]]}')
            ->setDistanceM(24000)->setState(ItemState::Submitted)
            ->setSource(ItemSource::User)->setSourceRef('user:'.uniqid('', true))
            ->setRegionId($regionId)->setProposedBy($this->proposer($em));
        $em->persist($r);
        $em->flush();

        return $r;
    }

    public function testProposalsPageAndCountTheSameSet(): void
    {
        static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $queue = static::getContainer()->get(RouteQueue::class);
        $scope = ModerationScope::global();

        for ($i = 0; $i < 3; ++$i) {
            $this->submitted($em, sprintf('Paged proposal %d', $i), null);
        }

        self::assertSame(3, $queue->pendingCount($scope, null));

        $first = $queue->pending($scope, null, 1, 2);
        $second = $queue->pending($scope, null, 2, 2);
        self::assertCount(2, $first);
        self::assertCount(1, $second);
        // Oldest first, and no row repeated or dropped at the boundary.
        self::assertCount(3, array_unique([
            ...array_column($first, 'id'),
            ...array_column($second, 'id'),
        ]));
    }

    public function testCorrectionsPageAndCountTheSameSet(): void
    {
        static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $queue = static::getContainer()->get(RouteQueue::class);
        $scope = ModerationScope::global();

        $route = $this->submitted($em, 'Correctable route', null);
        for ($i = 0; $i < 3; ++$i) {
            $em->persist(new RouteSuggestion(
                (int) $route->getId(),
                $this->proposer($em),
                RouteSuggestionReason::BrokenTrack,
                sprintf('Gate %d blocks the path.', $i),
            ));
        }
        $em->flush();

        self::assertSame(3, $queue->pendingSuggestionsCount($scope, null));
        self::assertCount(2, $queue->pendingSuggestions($scope, null, 1, 2));
        self::assertCount(1, $queue->pendingSuggestions($scope, null, 2, 2));
    }

    /**
     * The pager's count follows the region filter; the badge's does not. Both
     * are correct and they are different numbers — this pins that they stay
     * different.
     */
    public function testTheRegionFilterNarrowsThePagerCountButNotTheBadge(): void
    {
        static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $queue = static::getContainer()->get(RouteQueue::class);
        $scope = ModerationScope::global();

        $region = (new Region())->setSlug('rq-paging-region')->setName('Paging Region')->setCountryCode('BE')
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[4.0,49.5],[6.5,49.5],[6.5,51.0],[4.0,51.0],[4.0,49.5]]]]}');
        $em->persist($region);
        $em->flush();
        $regionId = (int) $region->getId();

        $this->submitted($em, 'In the filtered region', $regionId);
        $this->submitted($em, 'Somewhere else entirely', null);

        self::assertSame(1, $queue->pendingCount($scope, $regionId), 'the pager counts the filtered view');
        self::assertSame(2, $queue->total($scope), 'the badge keeps counting the whole scope');
    }
}
