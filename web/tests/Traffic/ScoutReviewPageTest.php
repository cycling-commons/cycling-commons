<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Traffic;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The ride review page carries what the traffic step needs
 * (docs/specs/traffic-measurements.md §2, §3); the plain map does not.
 */
final class ScoutReviewPageTest extends WebTestCase
{
    private function rider(KernelBrowser $client): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('review-page@example.com');
        $user->setPassword('x');
        $user->setDisplayName('Review rider');
        $user->setEmailVerified(true);
        $em->persist($user);
        $em->flush();
        $client->loginUser($user);
    }

    public function testTheReviewPageListsTheRoadPieceArchives(): void
    {
        $client = static::createClient();
        $this->rider($client);

        $client->request('GET', '/scout/review');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('"roadpieces":{}', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('window.CC_TRAFFIC_TOKEN', (string) $client->getResponse()->getContent());
    }

    public function testThePlainMapDoesNotFetchThem(): void
    {
        $client = static::createClient();
        $client->request('GET', '/map');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('"roadpieces"', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('CC_TRAFFIC_TOKEN', (string) $client->getResponse()->getContent());
    }
}
