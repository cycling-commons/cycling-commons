<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Map\MapHint;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * POST /map/hint/{hint}/close (map-and-search.md §4.5): a map hint a person
 * has read stays closed, on every device, because the answer is stored on the
 * account. No settings screen shows it (owner 2026-10-08).
 */
final class MapHintControllerTest extends WebTestCase
{
    private function makeUser(EntityManagerInterface $em, string $email): User
    {
        $user = (new User())->setEmail($email)->setPassword('x')->setRoles(['ROLE_CURATOR']);
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function token(): string
    {
        return static::getContainer()->get(CsrfTokenManagerInterface::class)->getToken('map-hint')->getValue();
    }

    private function close(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, string $hint, ?string $token = null): void
    {
        $client->request('POST', '/map/hint/'.$hint.'/close', [], [],
            ['HTTP_X_CSRF_TOKEN' => $token ?? $this->token(), 'HTTP_SEC_FETCH_SITE' => 'same-origin']);
    }

    public function testAnonymousGetsA401(): void
    {
        $client = static::createClient();
        $this->close($client, MapHint::PendingFollowsAreas->value);

        self::assertResponseStatusCodeSame(401);
    }

    public function testAClosedHintIsStoredAndHandedToTheMap(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->makeUser($em, 'map-hint-close@example.test');
        $client->loginUser($user);

        $this->close($client, MapHint::PendingFollowsAreas->value);
        self::assertResponseStatusCodeSame(204);

        $em->clear();
        $reloaded = $em->getRepository(User::class)->find($user->getId());
        self::assertInstanceOf(User::class, $reloaded);
        self::assertSame([MapHint::PendingFollowsAreas], $reloaded->getClosedHints());

        $client->request('GET', '/map');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('"closed":["pending_follows_areas"]', (string) $client->getResponse()->getContent());
    }

    public function testARiderClosesTheirOwnNote(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $rider = (new User())->setEmail('map-hint-rider@example.test')->setPassword('x');
        $em->persist($rider);
        $em->flush();
        $client->loginUser($rider);

        $this->close($client, MapHint::PendingYoursAnywhere->value);
        self::assertResponseStatusCodeSame(204);

        $em->clear();
        $reloaded = $em->getRepository(User::class)->find($rider->getId());
        self::assertInstanceOf(User::class, $reloaded);
        self::assertSame([MapHint::PendingYoursAnywhere], $reloaded->getClosedHints());
    }

    public function testClosingTwiceStoresItOnce(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->makeUser($em, 'map-hint-twice@example.test');
        $client->loginUser($user);

        $this->close($client, MapHint::PendingFollowsAreas->value);
        $this->close($client, MapHint::PendingFollowsAreas->value);

        $em->clear();
        $reloaded = $em->getRepository(User::class)->find($user->getId());
        self::assertInstanceOf(User::class, $reloaded);
        self::assertSame([MapHint::PendingFollowsAreas], $reloaded->getClosedHints());
    }

    public function testAnUnknownHintIsA404AndStoresNothing(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->makeUser($em, 'map-hint-unknown@example.test');
        $client->loginUser($user);

        $this->close($client, 'anything_else');
        self::assertResponseStatusCodeSame(404);

        $em->clear();
        $reloaded = $em->getRepository(User::class)->find($user->getId());
        self::assertInstanceOf(User::class, $reloaded);
        self::assertSame([], $reloaded->getClosedHints());
    }

    public function testABadTokenIsForbidden(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $client->loginUser($this->makeUser($em, 'map-hint-csrf@example.test'));

        $this->close($client, MapHint::PendingFollowsAreas->value, 'not-a-token');

        self::assertResponseStatusCodeSame(403);
    }
}
