<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Entity\User;
use App\Messaging\CuratorRoom;
use App\Messaging\CuratorRoomCategory;
use App\Messaging\CuratorRoomPin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The curator room: the in-desk board the rulebook points at.
 *
 * @see docs/specs/moderation-and-contribution.md §13
 */
final class CuratorRoomTest extends WebTestCase
{
    private int $seq = 0;

    /**
     * @param list<string> $roles
     */
    private function makeUser(string $name, array $roles): User
    {
        $container = static::getContainer();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail(sprintf('room-%s-%d@example.test', $name, ++$this->seq));
        $user->setDisplayName('room-'.$name);
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles($roles);
        $user->setPassword($hasher->hashPassword($user, 'hunter2secure!'));
        // Elevated roles must be TOTP-enrolled or TwoFactorSetupEnforcer redirects.
        $user->setTotpSecret('JBSWY3DPEHPK3PXP');
        $user->setTwoFaEnabled(true);
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function room(): CuratorRoom
    {
        /** @var CuratorRoom $room */
        $room = static::getContainer()->get(CuratorRoom::class);

        return $room;
    }

    /**
     * @param list<string> $roles
     */
    private function loginAs(KernelBrowser $client, string $name, array $roles): User
    {
        $user = $this->makeUser($name, $roles);
        $client->loginUser($user);

        return $user;
    }

    public function testAnonymousIsSentToLogin(): void
    {
        $client = static::createClient();

        $client->request('GET', '/moderate/room');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testPlainRiderIsRefused(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'rider', ['ROLE_USER']);

        $client->request('GET', '/moderate/room');

        self::assertResponseStatusCodeSame(403);
    }

    public function testCuratorOpensTheRoomAndTheRulebookLinksIt(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'curator', ['ROLE_CURATOR']);

        $crawler = $client->request('GET', '/moderate/room');

        self::assertResponseIsSuccessful();
        // The composer is the point of the page.
        self::assertGreaterThan(0, $crawler->filter('form.rm-compose textarea[name="body"]')->count());

        // The rulebook's dead literal link is now a real route.
        $rulebook = $client->request('GET', '/moderate/rulebook');
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $rulebook->filter('a.rb-screen[href$="/moderate/room"]')->count());
    }

    public function testTheWholeLoopThroughTheBrowser(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'loop', ['ROLE_CURATOR']);

        $crawler = $client->request('GET', '/moderate/room');
        $form = $crawler->filter('form.rm-compose')->form();
        $form['body'] = 'The gate at the top of the col is locked.';
        $form['category'] = 'tools';
        $client->submit($form);

        self::assertResponseRedirects();
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Posted');
        self::assertStringContainsString('The gate at the top of the col is locked.', $crawler->filter('.rm-post')->text());

        // Pin it to the room, then find it above a different category's view.
        $pin = $crawler->filter('.rm-post form[action$="/moderate/room/pin"]')->form();
        $pin['pin'] = 'room';
        $client->submit($pin);
        $client->followRedirect();

        $rules = $client->request('GET', '/moderate/room?c=rules');
        self::assertStringContainsString('The gate at the top of the col is locked.', $rules->filter('.rm-post.rm-pinned')->text());

        // And delete it again, because it is this curator's own post.
        $delete = $rules->filter('.rm-post form[action$="/moderate/room/delete"]')->form();
        $client->submit($delete);
        $client->followRedirect();

        $after = $client->request('GET', '/moderate/room');
        self::assertSame(0, $after->filter('.rm-post')->count());
    }

    public function testAPostToEveryoneReachesAnotherCurator(): void
    {
        $client = static::createClient();
        $author = $this->loginAs($client, 'author', ['ROLE_CURATOR']);
        $reader = $this->makeUser('reader', ['ROLE_CURATOR']);

        $this->room()->post((int) $author->getId(), CuratorRoomCategory::Ask, null, 'Is this hut inside my area?');

        $board = $this->room()->board((int) $reader->getId(), CuratorRoomCategory::VIEW_ALL);
        $bodies = array_column($board['posts'], 'body');

        self::assertContains('Is this hut inside my area?', $bodies);
    }

    public function testADirectMessageIsHiddenFromEveryoneElse(): void
    {
        static::createClient();
        $author = $this->makeUser('dm-author', ['ROLE_CURATOR']);
        $recipient = $this->makeUser('dm-recipient', ['ROLE_CURATOR']);
        $stranger = $this->makeUser('dm-stranger', ['ROLE_CURATOR']);

        $this->room()->post((int) $author->getId(), null, (int) $recipient->getId(), 'Between us two only.');

        $forRecipient = array_column($this->room()->board((int) $recipient->getId(), CuratorRoomCategory::VIEW_ALL)['posts'], 'body');
        $forAuthor = array_column($this->room()->board((int) $author->getId(), CuratorRoomCategory::VIEW_ALL)['posts'], 'body');
        $forStranger = array_column($this->room()->board((int) $stranger->getId(), CuratorRoomCategory::VIEW_ALL)['posts'], 'body');

        self::assertContains('Between us two only.', $forRecipient);
        self::assertContains('Between us two only.', $forAuthor);
        self::assertNotContains('Between us two only.', $forStranger);
    }

    public function testADirectMessageCannotBePinned(): void
    {
        static::createClient();
        $author = $this->makeUser('pin-author', ['ROLE_CURATOR']);
        $recipient = $this->makeUser('pin-recipient', ['ROLE_CURATOR']);

        $post = $this->room()->post((int) $author->getId(), null, (int) $recipient->getId(), 'Quiet word.');

        $this->expectException(\InvalidArgumentException::class);
        $this->room()->pin((int) $post->getId(), CuratorRoomPin::Room);
    }

    public function testARoomPinFloatsInsideACategoryView(): void
    {
        static::createClient();
        $author = $this->makeUser('pinner', ['ROLE_CURATOR']);
        $reader = $this->makeUser('pin-reader', ['ROLE_CURATOR']);

        $post = $this->room()->post((int) $author->getId(), CuratorRoomCategory::Rules, null, 'Read this first.');
        $this->room()->pin((int) $post->getId(), CuratorRoomPin::Room);

        $tools = $this->room()->board((int) $reader->getId(), CuratorRoomCategory::Tools->value);
        $rules = $this->room()->board((int) $reader->getId(), CuratorRoomCategory::Rules->value);

        self::assertContains('Read this first.', array_column($tools['pinned'], 'body'), 'a room pin shows in every view');
        self::assertContains('Read this first.', array_column($rules['pinned'], 'body'));
        self::assertNotContains('Read this first.', array_column($rules['posts'], 'body'), 'a pinned post is not listed twice');
    }

    public function testACategoryPinStaysInItsOwnCategory(): void
    {
        static::createClient();
        $author = $this->makeUser('cat-pinner', ['ROLE_CURATOR']);
        $reader = $this->makeUser('cat-reader', ['ROLE_CURATOR']);

        $post = $this->room()->post((int) $author->getId(), CuratorRoomCategory::Tools, null, 'The drawer eats clicks.');
        $this->room()->pin((int) $post->getId(), CuratorRoomPin::Category);

        $tools = $this->room()->board((int) $reader->getId(), CuratorRoomCategory::Tools->value);
        $all = $this->room()->board((int) $reader->getId(), CuratorRoomCategory::VIEW_ALL);

        self::assertContains('The drawer eats clicks.', array_column($tools['pinned'], 'body'));
        self::assertNotContains('The drawer eats clicks.', array_column($all['pinned'], 'body'), 'a category pin does not float in All');
        self::assertContains('The drawer eats clicks.', array_column($all['posts'], 'body'));
    }

    public function testOnlyTheAuthorDeletes(): void
    {
        static::createClient();
        $author = $this->makeUser('del-author', ['ROLE_CURATOR']);
        $other = $this->makeUser('del-other', ['ROLE_CURATOR']);

        $post = $this->room()->post((int) $author->getId(), null, null, 'Mine to remove.');

        self::assertFalse($this->room()->deleteOwn((int) $post->getId(), (int) $other->getId()));
        self::assertTrue($this->room()->deleteOwn((int) $post->getId(), (int) $author->getId()));
    }

    public function testTheBadgeCountsWhatArrivedSinceTheLastVisit(): void
    {
        static::createClient();
        $reader = $this->makeUser('badge-reader', ['ROLE_CURATOR']);
        $author = $this->makeUser('badge-author', ['ROLE_CURATOR']);
        $readerId = (int) $reader->getId();

        // Never visited: the room's history is history, not unread.
        $this->room()->post((int) $author->getId(), null, null, 'Before the first visit.');
        self::assertSame(0, $this->room()->unreadCount($readerId));

        $this->room()->markSeen($readerId);
        self::assertSame(0, $this->room()->unreadCount($readerId));

        // Timestamps have one-second resolution, so age the past rather than
        // race the clock: everything written so far moves an hour back, and the
        // visit a minute back, which puts the visit after them and before what
        // the rest of this test writes.
        $db = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $db->executeStatement("UPDATE curator_post SET created_at = created_at - interval '1 hour'");
        $db->executeStatement("UPDATE curator_room_visit SET last_seen_at = last_seen_at - interval '1 minute' WHERE user_id = :id", ['id' => $readerId]);

        $this->room()->post((int) $author->getId(), null, null, 'After the visit.');
        $this->room()->post((int) $author->getId(), null, (int) $reader->getId(), 'Directly to you.');
        $this->room()->post($readerId, null, null, 'My own post does not badge me.');
        $this->room()->post((int) $author->getId(), null, (int) $author->getId(), 'A note to somebody else.');

        self::assertSame(2, $this->room()->unreadCount($readerId));
    }

    public function testAnEmptyPostIsRefused(): void
    {
        static::createClient();
        $author = $this->makeUser('empty', ['ROLE_CURATOR']);

        $this->expectException(\InvalidArgumentException::class);
        $this->room()->post((int) $author->getId(), null, null, "   \n  ");
    }

    public function testARecipientWithoutTheRoleIsRefused(): void
    {
        static::createClient();
        $author = $this->makeUser('addressing', ['ROLE_CURATOR']);
        $rider = $this->makeUser('outsider', ['ROLE_USER']);

        $this->expectException(\InvalidArgumentException::class);
        $this->room()->post((int) $author->getId(), null, (int) $rider->getId(), 'You should not receive this.');
    }
}
