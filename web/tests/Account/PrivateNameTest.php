<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Account;

use App\Catalog\RiderPseudonym;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The settings page names the rider# handle others see while Public profile
 * is off (owner 2026-10-01: "Show users private name").
 */
final class PrivateNameTest extends WebTestCase
{
    public function testSettingsShowTheRidersPrivateName(): void
    {
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())
            ->setEmail('private-name-'.bin2hex(random_bytes(4)).'@example.com')
            ->setDisplayName('Named Rider')
            ->setEmailVerified(true)
            ->setEmailVerifiedAt(new \DateTimeImmutable())
            ->setRoles([])
            ->setPassword('x');
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/account/settings');
        self::assertResponseIsSuccessful();
        $line = $crawler->filter('[data-private-name]');
        self::assertCount(1, $line);
        self::assertSame(RiderPseudonym::PREFIX.$user->getPseudonym(), $line->filter('b')->text());
        self::assertStringContainsString('while Public profile is off', $line->text());
    }
}
