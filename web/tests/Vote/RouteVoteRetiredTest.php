<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Vote;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RouteVoteRetiredTest extends KernelTestCase
{
    public function testEveryVoteLivesInTheSeasonBallotTable(): void
    {
        self::bootKernel();
        $db = static::getContainer()->get(Connection::class);

        self::assertNull($db->fetchOne("SELECT to_regclass('route_vote')"));
        self::assertNotNull($db->fetchOne("SELECT to_regclass('season_vote')"));
        self::assertNotNull($db->fetchOne("SELECT to_regclass('season_result')"));
        self::assertFalse(class_exists('App\Catalog\Entity\RouteVote'));
    }
}
