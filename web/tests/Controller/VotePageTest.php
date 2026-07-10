<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class VotePageTest extends WebTestCase
{
    public function testVotePageHasNoRoutesBallotTab(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $u = (new User())->setEmail('voter@test.test')->setDisplayName('V');
        $u->setEmailVerified(true)->setEmailVerifiedAt(new \DateTimeImmutable())->setRoles([]);
        $u->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($u, 'password1234'));
        $em->persist($u);
        $em->flush();

        $client->loginUser($u);
        $crawler = $client->request('GET', '/vote');
        self::assertResponseIsSuccessful();
        // The four category tabs remain; the routes tab is retired (routes vote via the map drawer).
        self::assertSame(0, $crawler->filter('.vtabs button')->reduce(
            fn ($n) => str_contains($n->attr('onclick') ?? '', "'routes'")
        )->count());
    }
}
