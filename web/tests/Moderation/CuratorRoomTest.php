<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\Region;
use App\Catalog\Entity\Submission;
use App\Catalog\SubmissionType;
use App\Entity\User;
use App\Messaging\CuratorRoom;
use App\Messaging\CuratorRoomCategory;
use App\Messaging\CuratorRoomPin;
use App\Moderation\Entity\ModeratorArea;
use App\Tests\Support\RunsPictureChecks;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The curator room: the in-desk board the rulebook points at.
 *
 * @see docs/specs/moderation-and-contribution.md §13
 */
final class CuratorRoomTest extends WebTestCase
{
    use RunsPictureChecks;

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
        // The board lists; writing has its own page, one link away.
        self::assertSame(0, $crawler->filter('form.rm-compose')->count());
        $compose = $client->click($crawler->filter('a[href$="/moderate/room/new"]')->link());
        self::assertGreaterThan(0, $compose->filter('form.rm-compose textarea[name="body"]')->count());

        // The rulebook's dead literal link is now a real route.
        $rulebook = $client->request('GET', '/moderate/rulebook');
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $rulebook->filter('a.rb-screen[href$="/moderate/room"]')->count());
    }

    public function testTheWholeLoopThroughTheBrowser(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'loop', ['ROLE_CURATOR']);

        $crawler = $client->request('GET', '/moderate/room/new');
        $form = $crawler->filter('form.rm-compose')->form();
        $form['body'] = 'The gate at the top of the col is locked.';
        $form['title'] = 'Test post';
        $form['category'] = 'tools';
        $client->submit($form);

        self::assertResponseRedirects();
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Posted');
        self::assertStringContainsString('The gate at the top of the col is locked.', $crawler->filter('.rm-post')->text());

        // Pin it, from the edit page, then find it above a different category's view.
        $crawler = $client->click($crawler->filter('.rm-post a.rm-edit-ic')->link());
        $form = $crawler->filter('form.rm-compose')->form();
        $form['pin'] = 'room';
        $client->submit($form);
        $client->followRedirect();

        $rules = $client->request('GET', '/moderate/room?c=rules');
        self::assertStringContainsString('The gate at the top of the col is locked.', $rules->filter('.rm-post.rm-pinned')->text());
        // The board carries no pin control and no delete: those live on the edit page.
        self::assertSame(0, $rules->filter('.rm-post select')->count());
        self::assertSame(0, $rules->filter('.rm-post form[action$="/moderate/room/delete"]')->count());

        // And delete it again, from its edit page, because it is this curator's own post.
        $crawler = $client->click($rules->filter('.rm-post a.rm-edit-ic')->link());
        $delete = $crawler->filter('form.rm-del')->form();
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

    /**
     * Curators are named to each other in the room by display name; the name
     * links to the author's profile only when that profile is public
     * (DeskRider::colleague).
     */
    public function testAnAuthorIsNamedAndLinkedOnlyWithAPublicProfile(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'reader', ['ROLE_CURATOR']);
        $open = $this->makeUser('open', ['ROLE_CURATOR']);
        $open->setPublicProfile(true);
        $closed = $this->makeUser('closed', ['ROLE_CURATOR']);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->room()->post((int) $open->getId(), CuratorRoomCategory::Ask, null, 'Posted by a public curator');
        $this->room()->post((int) $closed->getId(), CuratorRoomCategory::Ask, null, 'Posted by a private curator');

        $crawler = $client->request('GET', '/moderate/room');
        self::assertResponseIsSuccessful();
        $public = $crawler->filter('.rm-post')->reduce(static fn (Crawler $p): bool => str_contains($p->text(), 'Posted by a public curator'));
        $private = $crawler->filter('.rm-post')->reduce(static fn (Crawler $p): bool => str_contains($p->text(), 'Posted by a private curator'));
        self::assertSame(1, $public->count());
        self::assertSame(1, $private->count());

        $link = $public->filter('.rm-who a.desk-rider');
        self::assertSame(1, $link->count());
        self::assertSame('room-open', trim($link->text()));
        self::assertStringEndsWith('/riders/'.$open->getUuid(), (string) $link->attr('href'));

        self::assertSame(0, $private->filter('.rm-who a')->count());
        self::assertSame('room-closed', trim($private->filter('.rm-who')->text()));
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

    public function testOnlyTheAuthorOrAnAdminDeletes(): void
    {
        static::createClient();
        $author = $this->makeUser('del-author', ['ROLE_CURATOR']);
        $other = $this->makeUser('del-other', ['ROLE_CURATOR']);

        $post = $this->room()->post((int) $author->getId(), null, null, 'Mine to remove.', title: 'Mine');

        self::assertFalse($this->room()->deleteOwn((int) $post->getId(), (int) $other->getId()));
        self::assertTrue($this->room()->deleteOwn((int) $post->getId(), (int) $author->getId()));

        // An administrator may take down any post, and reaches its edit page.
        $post = $this->room()->post((int) $author->getId(), null, null, 'Admin may remove.', title: 'Admin');
        self::assertTrue($this->room()->deleteOwn((int) $post->getId(), (int) $other->getId(), admin: true));
    }

    public function testAnAdminEditsAnotherCuratorsPost(): void
    {
        $client = static::createClient();
        $author = $this->makeUser('adm-author', ['ROLE_CURATOR']);
        $post = $this->room()->post((int) $author->getId(), null, null, 'Written by a curator.', title: 'Curator');

        $this->loginAs($client, 'adm', ['ROLE_ADMIN']);
        $crawler = $client->request('GET', '/moderate/room');
        self::assertSame(1, $crawler->filter('.rm-post a.rm-edit-ic')->count(), 'the admin sees the pencil on a post that is not theirs');
        $crawler = $client->request('GET', '/moderate/room/'.$post->getId().'/edit');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form.rm-compose')->form();
        $form['body'] = 'Reworded by an administrator.';
        $form['title'] = 'Test post';
        $client->submit($form);
        $crawler = $client->followRedirect();
        self::assertStringContainsString('Reworded by an administrator.', $crawler->filter('.rm-post')->text());
    }

    public function testTheBadgeCountsEveryPostTheReaderHasNotOpened(): void
    {
        static::createClient();
        $reader = $this->makeUser('badge-reader', ['ROLE_CURATOR']);
        $author = $this->makeUser('badge-author', ['ROLE_CURATOR']);
        $readerId = (int) $reader->getId();
        $before = $this->room()->unreadCount($readerId);

        $this->room()->post((int) $author->getId(), null, null, 'To everyone.');
        $this->room()->post((int) $author->getId(), null, $readerId, 'Directly to you.');
        $this->room()->post($readerId, null, null, 'My own post does not badge me.');
        $this->room()->post((int) $author->getId(), null, (int) $author->getId(), 'A note to somebody else.');

        self::assertSame($before + 2, $this->room()->unreadCount($readerId));
    }

    public function testLoadingTheRoomMarksNothing(): void
    {
        $client = static::createClient();
        $author = $this->makeUser('load-author', ['ROLE_CURATOR']);
        $reader = $this->loginAs($client, 'load-reader', ['ROLE_CURATOR']);
        $readerId = (int) $reader->getId();
        $a = $this->room()->post((int) $author->getId(), null, null, 'First unread.', title: 'Unread one');
        $this->room()->post((int) $author->getId(), null, null, 'Second unread.', title: 'Unread two');
        $before = $this->room()->unreadCount($readerId);

        $crawler = $client->request('GET', '/moderate/room');
        self::assertResponseIsSuccessful();
        $client->request('GET', '/moderate/room?c=direct');
        $client->request('GET', '/moderate');

        self::assertSame($before, $this->room()->unreadCount($readerId), 'a list page marks nothing, however often it loads');
        // Unread wears the one unseen bar with its words, and the post is closed until opened.
        $card = $crawler->filter('#post-'.$a->getId());
        self::assertStringContainsString('is-unseen', (string) $card->attr('class'));
        self::assertSame('Not opened yet', trim($card->filter('.unseen-note')->text()));
        self::assertSame('is-unseen', $card->attr('data-unread-class'));
        self::assertCount(1, $card->filter('details.rm-open:not([open])'));
        self::assertSame('/moderate/room/'.$a->getId().'/read', $card->attr('data-read-url'));
    }

    public function testOpeningOnePostLowersTheRoomCountByOne(): void
    {
        $client = static::createClient();
        $author = $this->makeUser('open-author', ['ROLE_CURATOR']);
        $reader = $this->loginAs($client, 'open-reader', ['ROLE_CURATOR']);
        $readerId = (int) $reader->getId();
        $a = $this->room()->post((int) $author->getId(), null, null, 'Open me.', title: 'Open me');
        $this->room()->post((int) $author->getId(), null, null, 'Leave me.', title: 'Leave me');
        $elsewhere = $this->room()->post((int) $author->getId(), null, (int) $author->getId(), 'Not yours.', title: 'Not yours');
        $mine = $this->room()->post($readerId, null, null, 'My own.', title: 'My own');
        $before = $this->room()->unreadCount($readerId);

        $crawler = $client->request('GET', '/moderate/room');
        self::assertSame((string) $before, trim($crawler->filter('.dtabs-modmode a[href$="/moderate/room"] .dtab-count')->text()), 'the room tab counts what is unread, on the room itself too');
        $token = (string) $crawler->filter('[data-read-token]')->attr('data-read-token');

        $client->request('POST', '/moderate/room/'.$a->getId().'/read', ['_token' => $token]);
        self::assertResponseIsSuccessful();
        self::assertSame(['read' => true, 'unread' => $before - 1], json_decode((string) $client->getResponse()->getContent(), true));
        self::assertSame($before - 1, $this->room()->unreadCount($readerId));

        // Again: nothing more comes off.
        $client->request('POST', '/moderate/room/'.$a->getId().'/read', ['_token' => $token]);
        self::assertSame(['read' => false, 'unread' => $before - 1], json_decode((string) $client->getResponse()->getContent(), true));

        // Your own post was never unread; somebody else's direct post is not yours to open.
        $client->request('POST', '/moderate/room/'.$mine->getId().'/read', ['_token' => $token]);
        self::assertSame(['read' => false, 'unread' => $before - 1], json_decode((string) $client->getResponse()->getContent(), true));
        $client->request('POST', '/moderate/room/'.$elsewhere->getId().'/read', ['_token' => $token]);
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', '/moderate/room/'.$a->getId().'/read', ['_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);

        $crawler = $client->request('GET', '/moderate/room');
        self::assertStringNotContainsString('is-unseen', (string) $crawler->filter('#post-'.$a->getId())->attr('class'));
        self::assertCount(0, $crawler->filter('#post-'.$a->getId().' .unseen-note'));
        self::assertCount(0, $crawler->filter('#post-'.$a->getId().' details.rm-open'));
        self::assertSame($before - 1, $this->room()->unreadCount($readerId));
    }

    public function testArrivingFromAPostsSubmissionLinkMarksThatPostOnly(): void
    {
        $client = static::createClient();
        $author = $this->makeUser('link-author', ['ROLE_CURATOR']);
        $reader = $this->loginAs($client, 'link-reader', ['ROLE_CURATOR']);
        $readerId = (int) $reader->getId();
        $sub = $this->seedSubmission((int) $author->getId(), 'Linked fountain');
        $a = $this->room()->post((int) $author->getId(), null, null, 'Look at this one.', (int) $sub->getId(), title: 'Linked');
        $this->room()->post((int) $author->getId(), null, null, 'Something else.', title: 'Other');
        $before = $this->room()->unreadCount($readerId);

        $crawler = $client->request('GET', '/moderate/room');
        $href = (string) $crawler->filter('#post-'.$a->getId().' .rm-about a')->attr('href');
        self::assertStringContainsString('post='.$a->getId(), $href, 'the link names the post it comes from');

        $client->request('GET', $href);
        self::assertResponseIsSuccessful();
        self::assertSame($before - 1, $this->room()->unreadCount($readerId));

        // A post you may not see is not marked by naming it in a link.
        $hidden = $this->room()->post((int) $author->getId(), null, (int) $author->getId(), 'Private.', title: 'Private');
        $client->request('GET', '/moderate/submissions?post='.$hidden->getId());
        self::assertFalse((bool) static::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne(
            'SELECT 1 FROM curator_post_read WHERE user_id = :u AND post_id = :p',
            ['u' => $readerId, 'p' => $hidden->getId()],
        ));
    }

    /**
     * The migration from one visit stamp to per-post reads: every post older
     * than a curator's last visit arrives read, so nobody's count jumps. A
     * curator who never opened the room counted nothing, and still does.
     */
    public function testTheBackfillKeepsOldPostsRead(): void
    {
        static::createClient();
        $db = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $visitor = $this->makeUser('bf-visitor', ['ROLE_CURATOR']);
        $never = $this->makeUser('bf-never', ['ROLE_CURATOR']);
        $author = $this->makeUser('bf-author', ['ROLE_CURATOR']);
        $old = $this->room()->post((int) $author->getId(), null, null, 'Before the visit.', title: 'Old');
        $new = $this->room()->post((int) $author->getId(), null, null, 'After the visit.', title: 'New');
        $db->executeStatement("UPDATE curator_post SET created_at = now() - interval '2 hours' WHERE id = :id", ['id' => $old->getId()]);

        // The schema as it stood before the migration, inside this test's transaction.
        $db->executeStatement('DROP TABLE curator_post_read');
        $db->executeStatement('CREATE TABLE curator_room_visit (user_id BIGINT PRIMARY KEY, last_seen_at TIMESTAMP(0) WITH TIME ZONE NOT NULL)');
        $db->executeStatement("INSERT INTO curator_room_visit (user_id, last_seen_at) VALUES (:u, now() - interval '1 hour')", ['u' => $visitor->getId()]);

        require_once \dirname(__DIR__, 2).'/migrations/Version20260930200000.php';
        $migration = new \DoctrineMigrations\Version20260930200000($db, new \Psr\Log\NullLogger());
        $migration->up(new \Doctrine\DBAL\Schema\Schema());
        foreach ($migration->getSql() as $query) {
            $db->executeStatement($query->getStatement());
        }

        $read = static fn (User $u, $p): bool => (bool) $db->fetchOne(
            'SELECT 1 FROM curator_post_read WHERE user_id = :u AND post_id = :p',
            ['u' => $u->getId(), 'p' => $p->getId()],
        );
        self::assertTrue($read($visitor, $old), 'older than the last visit: read');
        self::assertFalse($read($visitor, $new), 'newer than the last visit: still unread');
        self::assertTrue($read($never, $old), 'never visited counted nothing, and still counts nothing');
        self::assertTrue($read($never, $new));
        self::assertFalse($read($author, $old), 'an author never reads their own post');
        self::assertFalse((bool) $db->fetchOne("SELECT to_regclass('curator_room_visit') IS NOT NULL"), 'the visit stamp is gone');
    }

    private function seedSubmission(int $userId, string $title): Submission
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $sub = (new Submission())->setType(SubmissionType::NewItem)->setLetter('N')->setUserId($userId)
            ->setTitle($title)
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setChanges([])->setPayload([]);
        $em->persist($sub);
        $em->flush();

        return $sub;
    }

    /** A real PNG on disk, for the composer's file input. */
    private function pngFile(string $colour = 'red'): string
    {
        $image = new \Imagick();
        $image->newImage(64, 48, $colour);
        $image->setImageFormat('png');
        $path = tempnam(sys_get_temp_dir(), 'room').'.png';
        file_put_contents($path, $image->getImageBlob());
        $image->clear();

        return $path;
    }

    private function tokenOn(Crawler $crawler, string $formClass): string
    {
        return (string) $crawler->filter('form.'.$formClass.' input[name=_token]')->attr('value');
    }

    public function testATypedNumberLinksTheSubmissionAndShowsItsTitle(): void
    {
        $client = static::createClient();
        $author = $this->loginAs($client, 'about', ['ROLE_CURATOR']);
        $sub = $this->seedSubmission((int) $author->getId(), 'Col du Rosier water point');

        $crawler = $client->request('GET', '/moderate/room/new');
        $form = $crawler->filter('form.rm-compose')->form();
        $form['body'] = 'Is this one in reach of anybody?';
        $form['title'] = 'Test post';
        // The no-script path: a number typed into the search box.
        $form['about_q'] = 'SUB-'.$sub->getId();
        $client->submit($form);
        $crawler = $client->followRedirect();

        $about = $crawler->filter('.rm-post .rm-about a');
        self::assertStringContainsString('SUB-'.$sub->getId().' · Col du Rosier water point', $about->text());
        self::assertStringContainsString('q=SUB-'.$sub->getId(), (string) $about->attr('href'));
    }

    public function testAnUnknownSubmissionNumberIsRefusedAndTheWordsSurvive(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'about-bad', ['ROLE_CURATOR']);

        $crawler = $client->request('GET', '/moderate/room/new');
        $form = $crawler->filter('form.rm-compose')->form();
        $form['body'] = 'Words that must not be lost.';
        $form['title'] = 'Test post';
        $form['about_q'] = '99999999';
        $client->submit($form);
        $crawler = $client->followRedirect();

        self::assertSelectorTextContains('.flash-error', 'no submission with that number');
        self::assertSame('Words that must not be lost.', $crawler->filter('#rm-body')->text());
        self::assertSame(0, $client->request('GET', '/moderate/room')->filter('.rm-post')->count());
    }

    public function testTheSearchFindsByNumberTitleAndRegionForCuratorsOnly(): void
    {
        $client = static::createClient();
        $author = $this->loginAs($client, 'search', ['ROLE_CURATOR']);
        $sub = $this->seedSubmission((int) $author->getId(), 'Fontaine de Malchamps');

        $client->request('GET', '/moderate/room/submissions?q=Malchamps');
        self::assertResponseIsSuccessful();
        /** @var list<array{id: int, title: string}> $found */
        $found = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame($sub->getId(), $found[0]['id']);
        self::assertSame('Fontaine de Malchamps', $found[0]['title']);

        $client->request('GET', '/moderate/room/submissions?q='.$sub->getId());
        /** @var list<array{id: int}> $byId */
        $byId = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame($sub->getId(), $byId[0]['id']);

        // A number is a prefix: the first digit alone still lists the card.
        $client->request('GET', '/moderate/room/submissions?q='.substr((string) $sub->getId(), 0, 1));
        /** @var list<array{id: int}> $byPrefix */
        $byPrefix = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertContains($sub->getId(), array_column($byPrefix, 'id'));

        $client->request('GET', '/moderate/room/submissions?q=');
        self::assertSame('[]', $client->getResponse()->getContent());

        $this->loginAs($client, 'search-rider', ['ROLE_USER']);
        $client->request('GET', '/moderate/room/submissions?q=Malchamps');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * The search reads the queue, so it follows the queue's rules: a curator
     * limited to an area finds only that area's cards (§9.2), and nobody finds
     * a card under legal hold (photo-uploads.md §6d). Posts stay unscoped.
     */
    public function testTheSearchIsScopedLikeTheQueueAndLeavesOutHeldSubmissions(): void
    {
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $mineRegion = (new Region())->setSlug('room-scope-mine-'.uniqid())->setName('Zwinland')->setCountryCode('BE');
        $otherRegion = (new Region())->setSlug('room-scope-other-'.uniqid())->setName('Elsewhere')->setCountryCode('NL');
        $em->persist($mineRegion);
        $em->persist($otherRegion);
        $em->flush();

        $scoped = $this->loginAs($client, 'search-scoped', ['ROLE_CURATOR']);
        $em->persist(new ModeratorArea((int) $scoped->getId(), (int) $mineRegion->getId(), null));
        $inArea = $this->seedSubmission((int) $scoped->getId(), 'Zwinbrunnen in my area');
        $inArea->setRegionId((int) $mineRegion->getId());
        $outside = $this->seedSubmission((int) $scoped->getId(), 'Zwinbrunnen elsewhere');
        $outside->setRegionId((int) $otherRegion->getId());
        $held = $this->seedSubmission((int) $scoped->getId(), 'Zwinbrunnen under hold');
        $held->setRegionId((int) $mineRegion->getId());
        $held->escalate((int) $scoped->getId(), 'Suspected illegal content.');
        $em->flush();

        $ids = function (string $q) use ($client): array {
            $client->request('GET', '/moderate/room/submissions?q='.rawurlencode($q));
            self::assertResponseIsSuccessful();

            return array_column((array) json_decode((string) $client->getResponse()->getContent(), true), 'id');
        };

        self::assertSame([$inArea->getId()], $ids('Zwinbrunnen'), 'only the card in the curator\'s area');
        self::assertSame([], $ids((string) $held->getId()), 'a held card is not found by its number');
        self::assertSame([], $ids('under hold'), 'nor by its title');

        $this->loginAs($client, 'search-everywhere', ['ROLE_CURATOR']);
        $found = $ids('Zwinbrunnen');
        self::assertContains($inArea->getId(), $found);
        self::assertContains($outside->getId(), $found, 'a curator without areas searches everywhere');
        self::assertNotContains($held->getId(), $found, 'and still never finds a held card');
    }

    /**
     * A post about a submission that goes under legal hold keeps its number
     * but loses the title, on the board and on the edit page, and the link, as
     * no desk lists a held card; a held number cannot be linked anew, and the
     * post still saves with its old link.
     */
    public function testAHeldSubmissionShowsNoTitleInTheRoomAndCannotBeLinked(): void
    {
        $client = static::createClient();
        $author = $this->loginAs($client, 'about-held', ['ROLE_CURATOR']);
        $sub = $this->seedSubmission((int) $author->getId(), 'Words under hold');
        $post = $this->room()->post((int) $author->getId(), null, null, 'Asked before the hold.', (int) $sub->getId(), title: 'About a card');
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $sub->escalate((int) $author->getId(), 'Suspected illegal content.');
        $em->flush();

        $crawler = $client->request('GET', '/moderate/room');
        $about = $crawler->filter('.rm-post .rm-about')->text();
        self::assertStringContainsString((string) $sub->getId(), $about);
        self::assertStringNotContainsString('Words under hold', $about);
        self::assertCount(0, $crawler->filter('.rm-post .rm-about a'));

        $crawler = $client->request('GET', '/moderate/room/'.$post->getId().'/edit');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Words under hold', (string) $client->getResponse()->getContent());
        $form = $crawler->filter('form.rm-compose')->form();
        $form['body'] = 'Reworded while the card is held.';
        $client->submit($form);
        $client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Saved');

        $crawler = $client->request('GET', '/moderate/room/new');
        $form = $crawler->filter('form.rm-compose')->form();
        $form['body'] = 'Linking a held card.';
        $form['title'] = 'Test post';
        $form['about_q'] = 'SUB-'.$sub->getId();
        $client->submit($form);
        $client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'no submission with that number');
    }

    public function testAPictureUploadsFirstAndThePostClaimsIt(): void
    {
        $client = static::createClient();
        $author = $this->loginAs($client, 'pics', ['ROLE_CURATOR']);
        $crawler = $client->request('GET', '/moderate/room/new');
        $token = (string) $crawler->filter('#rm-pics')->attr('data-token');

        // The composer's uploader: one picture, sent on its own, answered with an id.
        $client->request('POST', '/moderate/room/upload', ['_token' => $token], [
            'image' => new UploadedFile($this->pngFile(), 'shot.png', 'image/png', null, true),
        ], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseStatusCodeSame(201);
        /** @var array{id: int, url: string} $up */
        $up = json_decode((string) $client->getResponse()->getContent(), true);
        $checks = $this->takePictureChecks();
        self::assertCount(1, $checks, 'the worker scans and draws it, not the web host');

        // Held and not yet checked: served to nobody, its uploader included.
        $client->request('GET', $up['url']);
        self::assertResponseStatusCodeSame(404);
        $this->runPictureChecks($checks);

        // Its uploader sees it before it is posted; another curator does not.
        $client->request('GET', $up['url']);
        self::assertResponseIsSuccessful();
        self::assertSame('image/webp', $client->getResponse()->headers->get('Content-Type'));
        $other = $this->makeUser('pics-other', ['ROLE_CURATOR']);
        $client->loginUser($other);
        $client->request('GET', $up['url']);
        self::assertResponseStatusCodeSame(404);

        // The post claims it.
        $client->loginUser($author);
        $crawler = $client->request('GET', '/moderate/room/new');
        $form = $crawler->filter('form.rm-compose')->form();
        $form['body'] = 'The sign at the junction, photographed.';
        $form['title'] = 'Test post';
        $form['images'] = (string) json_encode([$up['id']]);
        $client->submit($form);
        $crawler = $client->followRedirect();
        $img = $crawler->filter('.rm-post .rm-images img');
        self::assertSame(1, $img->count());
        self::assertSame($up['url'], $img->attr('src'));

        // Now every curator sees it, a rider does not, and it cannot be claimed twice.
        $client->loginUser($other);
        $client->request('GET', $up['url']);
        self::assertResponseIsSuccessful();
        $cache = (string) $client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cache);
        self::assertStringContainsString('private', $cache);
        $this->loginAs($client, 'pics-rider', ['ROLE_USER']);
        $client->request('GET', $up['url']);
        self::assertResponseStatusCodeSame(403);

        $client->loginUser($author);
        $crawler = $client->request('GET', '/moderate/room/new');
        $form = $crawler->filter('form.rm-compose')->form();
        $form['body'] = 'Trying to reuse the same picture.';
        $form['title'] = 'Test post';
        $form['images'] = (string) json_encode([$up['id']]);
        $client->submit($form);
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'picture on this post is missing');
    }

    public function testAPictureOnADirectPostIsForItsTwoPeopleOnly(): void
    {
        $client = static::createClient();
        $author = $this->loginAs($client, 'dm-pic', ['ROLE_CURATOR']);
        $recipient = $this->makeUser('dm-pic-to', ['ROLE_CURATOR']);
        $crawler = $client->request('GET', '/moderate/room/new');

        // The no-script path: the file rides with the form itself.
        $client->request('POST', '/moderate/room/post', [
            '_token' => $this->tokenOn($crawler, 'rm-compose'),
            'title' => 'The plate on the gate',
            'body' => 'For your eyes: the plate on the gate.',
            'to' => (string) $recipient->getId(),
            'category' => '',
            'c' => 'all',
        ], ['image' => [new UploadedFile($this->pngFile('blue'), 'gate.png', 'image/png', null, true)]]);
        $this->drainPictureChecks();
        $crawler = $client->followRedirect();
        $src = (string) $crawler->filter('.rm-post .rm-images img')->attr('src');
        self::assertStringContainsString('/moderate/room/image/', $src);

        $client->loginUser($recipient);
        $client->request('GET', $src);
        self::assertResponseIsSuccessful();

        $this->loginAs($client, 'dm-pic-third', ['ROLE_CURATOR']);
        $client->request('GET', $src);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAPictureNotYetCheckedShowsAsWordsOnTheBoard(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'pic-wait', ['ROLE_CURATOR']);
        $crawler = $client->request('GET', '/moderate/room/new');
        $client->request('POST', '/moderate/room/post', [
            '_token' => $this->tokenOn($crawler, 'rm-compose'),
            'title' => 'Still being checked',
            'body' => 'The picture has not been through the worker yet.',
            'to' => '',
            'category' => '',
            'c' => 'all',
        ], ['image' => [new UploadedFile($this->pngFile(), 'wait.png', 'image/png', null, true)]]);
        $crawler = $client->followRedirect();

        self::assertCount(0, $crawler->filter('.rm-post .rm-images img'));
        self::assertStringContainsString('Picture being checked', $crawler->filter('.rm-post .rm-images')->text());
    }

    public function testAnUnpostedPictureCanBeTakenBackAndOldOnesAreSwept(): void
    {
        $client = static::createClient();
        $author = $this->loginAs($client, 'sweep', ['ROLE_CURATOR']);
        $crawler = $client->request('GET', '/moderate/room/new');
        $token = (string) $crawler->filter('#rm-pics')->attr('data-token');
        $client->request('POST', '/moderate/room/upload', ['_token' => $token], [
            'image' => new UploadedFile($this->pngFile(), 'shot.png', 'image/png', null, true),
        ]);
        /** @var array{id: int, url: string} $up */
        $up = json_decode((string) $client->getResponse()->getContent(), true);

        $client->request('POST', '/moderate/room/upload/'.$up['id'].'/remove', ['_token' => $token]);
        self::assertSame('{"removed":true}', $client->getResponse()->getContent());
        $client->request('GET', $up['url']);
        self::assertResponseStatusCodeSame(404);

        // The sweep: an unposted picture from yesterday goes, one from now stays.
        $client->request('POST', '/moderate/room/upload', ['_token' => $token], [
            'image' => new UploadedFile($this->pngFile(), 'shot.png', 'image/png', null, true),
        ]);
        /** @var array{id: int} $fresh */
        $fresh = json_decode((string) $client->getResponse()->getContent(), true);
        $this->drainPictureChecks();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement(
            "INSERT INTO curator_post_image (post_id, uploader_id, position, mime_type, bytes, byte_size, width, height, created_at)
             VALUES (NULL, :u, 0, 'image/webp', '\\x00'::bytea, 1, 1, 1, NOW() - INTERVAL '2 days')",
            ['u' => (int) $author->getId()],
        );
        $swept = $this->room()->collectUnclaimedImages();
        self::assertSame(1, $swept);
        $client->request('GET', '/moderate/room/image/'.$fresh['id']);
        self::assertResponseIsSuccessful();
    }

    public function testAPostCanBePinnedAtCreationAndEveryFieldChangedAfterwards(): void
    {
        $client = static::createClient();
        $author = $this->loginAs($client, 'edit', ['ROLE_CURATOR']);
        $colleague = $this->makeUser('edit-to', ['ROLE_CURATOR']);
        $sub = $this->seedSubmission((int) $author->getId(), 'The gate at Les Croisettes');

        $crawler = $client->request('GET', '/moderate/room/new');
        $form = $crawler->filter('form.rm-compose')->form();
        $form['body'] = 'Pinned from the start.';
        $form['title'] = 'Test post';
        $form['category'] = 'rules';
        $form['pin'] = 'room';
        $client->submit($form);
        $crawler = $client->followRedirect();
        self::assertStringContainsString('Pinned from the start.', $crawler->filter('.rm-post.rm-pinned')->text());
        // Posted shows; Edited does not, nothing changed yet.
        $meta = $crawler->filter('.rm-post .rm-meta')->text();
        self::assertStringContainsString('Posted', $meta);
        self::assertStringNotContainsString('Edited', $meta);

        // The edit page, prefilled.
        $edit = $crawler->filter('.rm-post a.rm-edit-ic');
        $crawler = $client->click($edit->link());
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form.rm-compose')->form();
        self::assertSame('Pinned from the start.', $form['body']->getValue());
        self::assertSame('rules', $form['category']->getValue());
        self::assertSame('room', $form['pin']->getValue());

        // Every field changes: words, category, pin off, a submission, and a recipient.
        $form['body'] = 'Reworded, filed under tools, no longer pinned.';
        $form['title'] = 'Test post';
        $form['category'] = 'tools';
        $form['pin'] = 'none';
        $form['about_q'] = (string) $sub->getId();
        $form['to'] = (string) $colleague->getId();
        $client->submit($form);
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Saved');
        $post = $crawler->filter('.rm-post')->first();
        self::assertStringContainsString('Reworded, filed under tools', $post->text());
        self::assertStringContainsString('Edited', $post->filter('.rm-meta')->text());
        self::assertStringContainsString('Tools', $post->filter('.rm-meta')->text());
        self::assertSame(0, $crawler->filter('.rm-post.rm-pinned')->count());
        self::assertStringContainsString('SUB-'.$sub->getId().' · The gate at Les Croisettes', $post->filter('.rm-about')->text());

        // Now direct: the colleague reads it, a third curator does not.
        $client->loginUser($colleague);
        $crawler = $client->request('GET', '/moderate/room?c=direct');
        self::assertStringContainsString('Reworded', $crawler->filter('.rm-post')->text());
        $this->loginAs($client, 'edit-third', ['ROLE_CURATOR']);
        $crawler = $client->request('GET', '/moderate/room');
        self::assertSame(0, $crawler->filter('.rm-post')->count());
    }

    public function testOnlyTheAuthorOrAnAdminReachesTheEditPage(): void
    {
        $client = static::createClient();
        $author = $this->loginAs($client, 'edit-own', ['ROLE_CURATOR']);
        $post = $this->room()->post((int) $author->getId(), null, null, 'Mine to change.', title: 'Mine');

        $this->loginAs($client, 'edit-other', ['ROLE_CURATOR']);
        $client->request('GET', '/moderate/room/'.$post->getId().'/edit');
        self::assertResponseStatusCodeSame(404);

        $client->loginUser($author);
        $client->request('GET', '/moderate/room/'.$post->getId().'/edit');
        self::assertResponseIsSuccessful();
    }

    public function testAPinnedDirectPostIsRefusedAtCreation(): void
    {
        $client = static::createClient();
        $author = $this->loginAs($client, 'pin-dm', ['ROLE_CURATOR']);
        $to = $this->makeUser('pin-dm-to', ['ROLE_CURATOR']);
        $this->expectException(\InvalidArgumentException::class);
        $this->room()->post((int) $author->getId(), null, (int) $to->getId(), 'Private and pinned?', null, [], [], CuratorRoomPin::Room, 'Private');
    }

    public function testATitleHeadsTheCardAndAnEmptyOneIsRefused(): void
    {
        $client = static::createClient();
        $this->loginAs($client, 'title', ['ROLE_CURATOR']);

        $crawler = $client->request('GET', '/moderate/room/new');
        $form = $crawler->filter('form.rm-compose')->form();
        $form['title'] = 'Gate at the top';
        $form['body'] = 'Locked since Tuesday.';
        $client->submit($form);
        $crawler = $client->followRedirect();
        self::assertSame('Gate at the top', $crawler->filter('.rm-post h3')->text());

        // The form requires a title; the server refuses a blank one and keeps the words.
        $crawler = $client->request('GET', '/moderate/room/new');
        $form = $crawler->filter('form.rm-compose')->form();
        $form['title'] = '   ';
        $form['body'] = 'No title here.';
        $client->submit($form);
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Give the post a title');
        self::assertSame('No title here.', $crawler->filter('#rm-body')->text());
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
