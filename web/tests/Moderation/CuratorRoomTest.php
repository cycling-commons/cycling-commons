<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\Submission;
use App\Catalog\SubmissionType;
use App\Entity\User;
use App\Messaging\CuratorRoom;
use App\Messaging\CuratorRoomCategory;
use App\Messaging\CuratorRoomPin;
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
        $form['images'] = json_encode([$up['id']]);
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
        $form['images'] = json_encode([$up['id']]);
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
            'body' => 'For your eyes: the plate on the gate.',
            'to' => (string) $recipient->getId(),
            'category' => '',
            'c' => 'all',
        ], ['image' => [new UploadedFile($this->pngFile('blue'), 'gate.png', 'image/png', null, true)]]);
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
        $edit = $crawler->filter('.rm-post a.rm-edit');
        $crawler = $client->click($edit->link());
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form.rm-compose')->form();
        self::assertSame('Pinned from the start.', $form['body']->getValue());
        self::assertSame('rules', $form['category']->getValue());
        self::assertSame('room', $form['pin']->getValue());

        // Every field changes: words, category, pin off, a submission, and a recipient.
        $form['body'] = 'Reworded, filed under tools, no longer pinned.';
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

    public function testOnlyTheAuthorReachesTheEditPage(): void
    {
        $client = static::createClient();
        $author = $this->loginAs($client, 'edit-own', ['ROLE_CURATOR']);
        $post = $this->room()->post((int) $author->getId(), null, null, 'Mine to change.');

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
        $this->room()->post((int) $author->getId(), null, (int) $to->getId(), 'Private and pinned?', null, [], [], CuratorRoomPin::Room);
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
