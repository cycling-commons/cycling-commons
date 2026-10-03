<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Vote;

use App\Catalog\BikeType;
use App\Catalog\Entity\Item;
use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\ItemType;
use App\Vote\BallotCandidates;
use App\Vote\Hemisphere;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BallotCandidatesTest extends KernelTestCase
{
    private Connection $db;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = static::getContainer()->get(Connection::class);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    private function region(string $slug, string $cc, float $lat, int $level = 4): int
    {
        $polygon = sprintf('POLYGON((5 %1$F,5 %2$F,6 %2$F,6 %1$F,5 %1$F))', $lat, $lat + 1);
        $this->db->executeStatement(
            "INSERT INTO region (slug, name, geom, area_km2, country_code, iso_code, admin_level, source, created_at, updated_at)
             VALUES (?, ?, ST_GeomFromText(?, 4326), 1000, ?, ?, ?, 'test', NOW(), NOW())",
            [$slug, ucfirst($slug), $polygon, $cc, strtoupper(substr($slug, 0, 8)), $level],
        );

        return (int) $this->db->fetchOne('SELECT id FROM region WHERE slug = ?', [$slug]);
    }

    private function item(string $letter, int $regionId, string $name, ItemState $state = ItemState::Verified): int
    {
        $item = (new Item())->setLetter($letter)->setName($name)
            ->setGeom('{"type":"Point","coordinates":[5.5,50.5]}')->setCountryCode('XA')
            ->setState($state)->setSource(ItemSource::Osm)->setSourceRef('node/'.bin2hex(random_bytes(4)))
            ->setAttributes([])->setRegionId($regionId);
        $this->em->persist($item);
        $this->em->flush();

        return (int) $item->getId();
    }

    /** @param array<string, mixed> $attributes */
    private function route(int $regionId, string $name, ItemState $state = ItemState::Verified, ?int $proposedBy = null, array $attributes = []): int
    {
        $route = (new RecommendedRoute())->setName($name)
            ->setGeom('{"type":"LineString","coordinates":[[5.2,50.4],[5.3,50.5]]}')
            ->setDistanceM(20000)->setState($state)->setSource(ItemSource::User)
            ->setSourceRef('user:ballot-'.bin2hex(random_bytes(6)))->setRegionId($regionId)->setProposedBy($proposedBy)
            ->setAttributes($attributes);
        $this->em->persist($route);
        $this->em->flush();

        return (int) $route->getId();
    }

    private function confirm(int $itemId, int $userId, string $source = 'drawer'): void
    {
        $this->db->executeStatement(
            "INSERT INTO item_confirmation (item_id, user_id, stance, source, by_curator, created_at, updated_at)
             VALUES (?, ?, 'exists', ?, false, NOW(), NOW())",
            [$itemId, $userId, $source],
        );
    }

    private function ride(int $routeId, int $userId): void
    {
        $this->db->executeStatement(
            "INSERT INTO route_ride (route_id, user_id, bike_type, created_at) VALUES (?, ?, 'Road', NOW())",
            [$routeId, $userId],
        );
    }

    public function testPlacesComeMostConfirmedFirstThenNewest(): void
    {
        $rid = $this->region('xa-north', 'XA', 50.0);
        $old = $this->item('N', $rid, 'Old climb');
        $busy = $this->item('N', $rid, 'Busy climb');
        $new = $this->item('N', $rid, 'New climb');
        $newer = $this->item('N', $rid, 'Newer climb');
        $this->confirm($busy, 1);
        $this->confirm($busy, 2);
        $this->confirm($old, 3);
        // The submitter's own answer on the form is not a confirmation.
        $this->confirm($new, 4, 'form');
        $this->item('P', $rid, 'A view');
        $this->item('N', $rid, 'Retired climb', ItemState::Retired);

        $top = (new BallotCandidates($this->db))->top(ItemType::Climbs, $rid, 10);

        self::assertSame([$busy, $old, $newer, $new], array_column($top, 'id'));
        self::assertSame([2, 1, 0, 0], array_column($top, 'confirmations'));
    }

    /** Owner 2026-10-03: the ballot lists A to Z, so how many have been there never moves a place up. */
    public function testTheBallotListsAToZWhateverTheConfirmations(): void
    {
        $rid = $this->region('xa-north', 'XA', 50.0);
        $zulu = $this->item('Q', $rid, 'Zulu Fort');
        $abbey = $this->item('Q', $rid, 'abbey of Alpha');
        $mill = $this->item('Q', $rid, 'Mill');
        $this->confirm($zulu, 1);
        $this->confirm($zulu, 2);

        $list = (new BallotCandidates($this->db))->alphabetical(ItemType::HistoryCulture, $rid, 10);

        self::assertSame([$abbey, $mill, $zulu], array_column($list, 'id'));
        self::assertSame([0, 0, 2], array_column($list, 'confirmations'));
    }

    public function testRoutesCountRidersButNotTheProposer(): void
    {
        $rid = $this->region('xa-north', 'XA', 50.0);
        $route = $this->route($rid, 'Loop', ItemState::Verified, 77);
        $this->ride($route, 77);
        $this->ride($route, 78);
        $this->route($rid, 'Waiting loop', ItemState::Unverified);

        $top = (new BallotCandidates($this->db))->top(ItemType::QualityRides, $rid, 10);

        self::assertSame([$route], array_column($top, 'id'));
        self::assertSame([1], array_column($top, 'confirmations'));
    }

    public function testASpecialtyBikeOnlyListsRoutesThatDeclareIt(): void
    {
        $rid = $this->region('xa-north', 'XA', 50.0);
        $declared = $this->route($rid, 'Handbike loop', ItemState::Verified, null, ['bikeTypes' => ['Handbike']]);
        $this->route($rid, 'Unmarked loop', ItemState::Verified, null, ['bikeTypes' => ['Road']]);
        $this->route($rid, 'Blank loop', ItemState::Verified);

        $top = (new BallotCandidates($this->db))->top(ItemType::QualityRides, $rid, 10, BikeType::Handbike);

        self::assertSame([$declared], array_column($top, 'id'));
    }

    public function testASubjectCarriesItsRegionAndHemisphere(): void
    {
        $north = $this->region('xa-north', 'XA', 50.0);
        $south = $this->region('xb-south', 'XB', -34.0);
        $c = new BallotCandidates($this->db);

        $n = $c->subject(ItemType::Climbs, $this->item('N', $north, 'Mur'));
        $s = $c->subject(ItemType::ScenicViews, $this->item('P', $south, 'Lookout'));

        self::assertNotNull($n);
        self::assertNotNull($s);
        self::assertSame($north, $n['regionId']);
        self::assertSame(Hemisphere::North, $n['hemisphere']);
        self::assertSame(Hemisphere::South, $s['hemisphere']);
    }

    public function testNothingOutsideTheBallotIsASubject(): void
    {
        $rid = $this->region('xa-north', 'XA', 50.0);
        // The country's own outline row: admin level 2 under a level-4 country, not operational.
        $outline = $this->region('xa', 'XA', 49.0, 2);
        $c = new BallotCandidates($this->db);

        self::assertNull($c->subject(ItemType::Climbs, $this->item('N', $outline, 'Edge climb')));
        self::assertNull($c->subject(ItemType::Climbs, $this->item('P', $rid, 'A view, not a climb')));
        self::assertNull($c->subject(ItemType::QualityRides, $this->route($rid, 'Waiting', ItemState::Unverified)));
        self::assertNull($c->subject(ItemType::WaterFood, $this->item('B', $rid, 'Tap')));
        self::assertNull($c->subject(ItemType::Climbs, 999999999));
    }

    public function testNamesConfirmationsAndWhatIsStillOnTheBallot(): void
    {
        $rid = $this->region('xa-north', 'XA', 50.0);
        $live = $this->item('Q', $rid, 'Castle');
        $gone = $this->item('Q', $rid, 'Ruin', ItemState::Retired);
        $this->confirm($gone, 5);
        $c = new BallotCandidates($this->db);

        $names = $c->names(ItemType::HistoryCulture, [$live, $gone]);
        ksort($names);
        self::assertSame([$live => 'Castle', $gone => 'Ruin'], $names);
        self::assertSame([$live], $c->onBallot(ItemType::HistoryCulture, [$live, $gone]));
        self::assertSame(1, $c->confirmations(ItemType::HistoryCulture, [$live, $gone])[$gone]);
    }
}
