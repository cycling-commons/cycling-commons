<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Ride tools are shown to everyone; logged out they are locked, not absent.
 *
 * Hiding a tool teaches nobody it exists. A locked one names what it does and
 * offers the single action that unlocks it, so the panel is the same shape for
 * every visitor.
 */
final class MapToolsLockedTest extends WebTestCase
{
    private function client(): KernelBrowser
    {
        return static::createClient();
    }

    public function testLoggedOutSeesEveryToolHeading(): void
    {
        $client = $this->client();
        $client->request('GET', '/map');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('Check my ride', $html);
        self::assertStringContainsString('Scout', $html);
        self::assertStringContainsString('Add a climb', $html);
        self::assertStringContainsString('Propose a route', $html);
        self::assertStringContainsString('How safe did a road feel?', $html);
    }

    public function testLoggedOutGetsLockedControlsPointingAtLogin(): void
    {
        $client = $this->client();
        $crawler = $client->request('GET', '/map');
        self::assertResponseIsSuccessful();

        // Ride check is open to everyone on purpose. It is the tool that earns
        // the account rather than requiring one.
        $locked = $crawler->filter('#p-tools .cc-locked');
        self::assertSame(3, $locked->count(), 'add-climb, propose-route and the road-safety marks are locked');
        foreach ($locked as $node) {
            self::assertStringContainsString('/login', (string) $node->getAttribute('href'));
        }
        self::assertStringNotContainsString('Log in to use this', $crawler->filter('#p-tools')->text());
    }

    /** Scout shows one plain link to everyone; a guest passes through login and comes back. */
    public function testLoggedOutScoutLinksTheReviewWithNoLoginText(): void
    {
        $client = $this->client();
        $crawler = $client->request('GET', '/map');

        self::assertSame(1, $crawler->filter('#p-tools a.cc-addclimb-go[href="/scout/review"]')->count());
    }

    public function testRideCheckIsUsableWithoutAnAccount(): void
    {
        $client = $this->client();
        $crawler = $client->request('GET', '/map');

        self::assertSame(1, $crawler->filter('#rcFile')->count(), 'the GPX input is there logged out');
        self::assertSame(0, $crawler->filter('#addClimbHere')->count(), 'add-climb still is not');
    }

    public function testLoggedInGetsTheRealControlsAndNoLocks(): void
    {
        $client = $this->client();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $rider = (new User())->setEmail('map-tools-locked@example.com');
        $rider->setPassword('x');
        $rider->setDisplayName('Tools Rider');
        $em->persist($rider);
        $em->flush();

        $client->loginUser($rider);
        $crawler = $client->request('GET', '/map');
        self::assertResponseIsSuccessful();

        self::assertSame(1, $crawler->filter('#rcFile')->count());
        self::assertSame(1, $crawler->filter('#addClimbHere')->count());
        self::assertSame(0, $crawler->filter('#p-tools .cc-locked')->count());
    }
}
