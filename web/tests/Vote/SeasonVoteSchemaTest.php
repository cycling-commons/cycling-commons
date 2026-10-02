<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Vote;

use App\Catalog\BikeType;
use App\Catalog\Entity\SeasonVote;
use App\Catalog\ItemType;
use App\Catalog\Season;
use App\Vote\ListKey;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SeasonVoteSchemaTest extends KernelTestCase
{
    private function db(): Connection
    {
        self::bootKernel();

        return static::getContainer()->get(Connection::class);
    }

    /** @param array<string, mixed> $overrides */
    private function insert(Connection $db, array $overrides = []): void
    {
        $db->insert('season_vote', $overrides + [
            'user_id' => 41, 'region_id' => 7, 'category' => 'climbs', 'subject_id' => 100,
            'bike_type' => null, 'season' => 'spring', 'round_start' => '2027-03-01', 'slot' => 1,
            'created_at' => '2027-04-10 12:00:00',
        ]);
    }

    public function testAVoteRoundTripsThroughTheEntity(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $vote = new SeasonVote(41, 7, ItemType::QualityRides, 100, BikeType::Gravel, Season::Spring, new \DateTimeImmutable('2027-03-01'), 2);
        $em->persist($vote);
        $em->flush();
        $em->clear();

        $found = $em->find(SeasonVote::class, $vote->getId());
        self::assertNotNull($found);
        self::assertSame(ItemType::QualityRides, $found->getCategory());
        self::assertSame(BikeType::Gravel, $found->getBikeType());
        self::assertSame('2027-03-01', $found->getRoundStart()->format('Y-m-d'));
        self::assertSame(2, $found->getSlot());
    }

    public function testAFourthSlotIsRefusedByTheDatabase(): void
    {
        $db = $this->db();
        $this->expectException(DriverException::class);
        $this->expectExceptionMessageMatches('/season_vote_slot_range/');
        $this->insert($db, ['slot' => 4]);
    }

    public function testTwoVotesCannotShareASlot(): void
    {
        $db = $this->db();
        $this->insert($db);
        $this->expectException(UniqueConstraintViolationException::class);
        $this->insert($db, ['subject_id' => 101]);
    }

    public function testOneRiderCannotVoteForTheSameItemTwiceInAList(): void
    {
        $db = $this->db();
        $this->insert($db);
        $this->expectException(UniqueConstraintViolationException::class);
        $this->insert($db, ['slot' => 2]);
    }

    public function testTheSameItemInAnotherRoundIsAnotherVote(): void
    {
        $db = $this->db();
        $this->insert($db);
        $this->insert($db, ['season' => 'summer', 'round_start' => '2027-06-01']);
        self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM season_vote WHERE user_id = 41'));
    }

    public function testAStoredResultRowIsUniquePerListAndItem(): void
    {
        $db = $this->db();
        $row = [
            'region_id' => 7, 'category' => 'climbs', 'bike_type' => '', 'season' => 'spring', 'round_start' => '2027-03-01',
            'subject_id' => 100, 'subject_name' => 'Mur', 'votes' => 6, 'score' => 24, 'handicapped' => false, 'place' => 1,
            'wins_before' => 0, 'list_position' => 1, 'voters' => 6, 'frozen_at' => '2027-06-01 01:00:00',
        ];
        $db->insert('season_result', $row, ['handicapped' => ParameterType::BOOLEAN]);
        $this->expectException(UniqueConstraintViolationException::class);
        $db->insert('season_result', $row, ['handicapped' => ParameterType::BOOLEAN]);
    }

    public function testAListKeyNarrowsOnlyARouteListByBike(): void
    {
        self::assertSame('', (new ListKey(7, ItemType::Climbs))->bikeColumn());
        self::assertSame('Gravel', (new ListKey(7, ItemType::QualityRides, BikeType::Gravel))->bikeColumn());
        $this->expectException(\InvalidArgumentException::class);
        new ListKey(7, ItemType::Climbs, BikeType::Gravel);
    }

    public function testAListKeyIsOnlyForAVotableKind(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ListKey(7, ItemType::WaterFood);
    }
}
