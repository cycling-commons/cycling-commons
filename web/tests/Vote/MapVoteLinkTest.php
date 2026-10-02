<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Vote;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MapVoteLinkTest extends WebTestCase
{
    public function testTheMapKnowsWhereTheBallotIs(): void
    {
        $client = static::createClient();
        $client->request('GET', '/map');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('window.CC_VOTE_URL = "/vote";', (string) $client->getResponse()->getContent());
    }
}
