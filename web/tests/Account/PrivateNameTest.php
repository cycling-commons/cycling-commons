<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Account;

use App\Catalog\RiderPseudonym;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The settings page shows, at the display name, the name others see: the
 * anonymous rider# name leads while Public profile is off and the display
 * name is dimmed, the other way round while it is on (owner 2026-10-01
 * "Show users private name", 2026-10-03 "anonymous name", shown at the name).
 */
final class PrivateNameTest extends WebTestCase
{
    private function rider(bool $public): User
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())
            ->setEmail('private-name-'.bin2hex(random_bytes(4)).'@example.com')
            ->setDisplayName('Named Rider')
            ->setEmailVerified(true)
            ->setEmailVerifiedAt(new \DateTimeImmutable())
            ->setRoles([])
            ->setPassword('x');
        $user->setPublicProfile($public);
        $em->persist($user);
        $em->flush();

        return $user;
    }

    public function testWithPublicProfileOffTheAnonymousNameLeads(): void
    {
        $client = static::createClient();
        $user = $this->rider(false);
        $client->loginUser($user);

        $crawler = $client->request('GET', '/account/settings');

        self::assertResponseIsSuccessful();
        $line = $crawler->filter('[data-seen-as]');
        self::assertCount(1, $line);
        self::assertStringContainsString('Others see:', $line->text());
        self::assertSame(RiderPseudonym::PREFIX.$user->getPseudonym(), $line->filter('[data-seen-main]')->text());
        self::assertSame('Named Rider', $line->filter('[data-seen-other]')->text());
        self::assertCount(0, $crawler->filter('[data-private-name]'), 'the sentence under the switch is gone');
    }

    public function testWithPublicProfileOnTheDisplayNameLeads(): void
    {
        $client = static::createClient();
        $user = $this->rider(true);
        $client->loginUser($user);

        $crawler = $client->request('GET', '/account/settings');

        $line = $crawler->filter('[data-seen-as]');
        self::assertSame('Named Rider', $line->filter('[data-seen-main]')->text());
        self::assertSame(RiderPseudonym::PREFIX.$user->getPseudonym(), $line->filter('[data-seen-other]')->text());
    }
}
