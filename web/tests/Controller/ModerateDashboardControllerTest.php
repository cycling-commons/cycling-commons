<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Messaging\CuratorRoom;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The curator dashboard (moderation-and-contribution.md §5.0): every desk on
 * one page, read-only.
 */
final class ModerateDashboardControllerTest extends WebTestCase
{
    public function testACuratorSeesEveryDeskLinkedFromOnePage(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->login($client, 'dash-curator@example.com', ['ROLE_CURATOR']);

        $crawler = $client->request('GET', '/moderate/dashboard');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Dashboard');
        self::assertSelectorExists('.dtabs-modmode a.on[href$="/moderate/dashboard"]', 'the dashboard tab is the active one');

        $tiles = $crawler->filter('a.db-tile')->each(static fn ($a): string => (string) $a->attr('href'));
        foreach (['/moderate/takedowns', '/moderate/reports', '/moderate', '/moderate/routes', '/moderate/data', '/moderate/bugs', '/moderate/translations', '/moderate/room'] as $desk) {
            self::assertContains($desk, $tiles, $desk.' has a tile');
        }
    }

    public function testTheDashboardShowsRoomPostsButLeavesThemUnread(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $author = $this->login($client, 'dash-author@example.com', ['ROLE_CURATOR']);
        $reader = $this->login($client, 'dash-reader@example.com', ['ROLE_CURATOR']);

        /** @var CuratorRoom $room */
        $room = self::getContainer()->get(CuratorRoom::class);
        $room->markSeen((int) $reader->getId());
        // Timestamps have one-second resolution: move the visit a minute back
        // so the post below lands after it.
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement(
            "UPDATE curator_room_visit SET last_seen_at = last_seen_at - interval '1 minute' WHERE user_id = :id",
            ['id' => (int) $reader->getId()],
        );
        $room->post((int) $author->getId(), null, null, 'Cobbles near Oudenaarde: keep or drop?', title: 'Dashboard test post');
        self::assertSame(1, $room->unreadCount((int) $reader->getId()));

        $client->request('GET', '/moderate/dashboard');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.db-list', 'Dashboard test post');
        self::assertSame(1, $room->unreadCount((int) $reader->getId()), 'only the room itself marks posts seen');
    }

    public function testARiderIsKeptOut(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->login($client, 'dash-rider@example.com', []);

        $client->request('GET', '/moderate/dashboard');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @param list<string> $roles
     */
    private function login(KernelBrowser $client, string $email, array $roles): User
    {
        $c = static::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Dash pen');
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles($roles);
        if ([] !== $roles) {
            $user->setTotpSecret('JBSWY3DPEHPK3PXP');
            $user->setTwoFaEnabled(true);
        }
        $user->setPassword($c->get(UserPasswordHasherInterface::class)->hashPassword($user, 'securepass12345!'));
        $em->persist($user);
        $em->flush();
        $client->loginUser($user);

        return $user;
    }
}
