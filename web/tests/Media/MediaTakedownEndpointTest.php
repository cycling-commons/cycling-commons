<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Media;

use App\Entity\User;
use App\Media\Entity\ConsentRecord;
use App\Media\Entity\MediaUpload;
use App\Media\MediaConsent;
use App\Media\MediaTakedownService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Who may ask for a photo to come down, and what the pages say afterwards
 * (docs/specs/photo-uploads.md §6b).
 */
final class MediaTakedownEndpointTest extends WebTestCase
{
    /**
     * Reboot disabled so the entity manager the test holds is the one the
     * requests use: KernelBrowser rebuilds the container between requests
     * otherwise, and every entity this test is holding becomes detached.
     */
    private function client(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    private function rider(EntityManagerInterface $em, string $email, string $name = 'Takedown Rider'): User
    {
        $user = (new User())->setEmail($email);
        $user->setPassword('x');
        $user->setDisplayName($name);
        $user->setPublicProfile(true);
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function approved(EntityManagerInterface $em, User $owner): MediaUpload
    {
        $ownerId = (int) $owner->getId();
        $consent = new ConsentRecord(Uuid::v4(), $ownerId, MediaConsent::KIND, MediaConsent::VERSION, MediaConsent::hash('x'));
        $em->persist($consent);

        $upload = new MediaUpload(Uuid::v4(), $ownerId, $consent->getId(), 'EU', 1200, 900, 4242);
        $em->persist($upload);
        $upload->claim(1);
        $upload->approve(null);
        $em->flush();

        return $upload;
    }

    /**
     * Re-reads the row. NOT $em->refresh(): the entity manager is
     * kernel.reset-tagged, so it is cleared at the start of every request and
     * anything the test is holding is detached by the time it looks again.
     */
    private function reload(EntityManagerInterface $em, MediaUpload $upload): MediaUpload
    {
        $fresh = $em->find(MediaUpload::class, $upload->getId());
        self::assertInstanceOf(MediaUpload::class, $fresh);

        return $fresh;
    }

    private function post(KernelBrowser $client, MediaUpload $upload, string $reason): void
    {
        $uuid = $upload->getId()->toRfc4122();
        $crawler = $client->request('GET', '/photo/'.$uuid);
        $token = (string) $crawler->filter('form[action$="/takedown"] input[name="_token"]')->attr('value');
        $client->request('POST', '/photo/'.$uuid.'/takedown', ['_token' => $token, 'reason' => $reason]);
    }

    public function testOnlyTheUploaderIsOfferedTheForm(): void
    {
        $client = $this->client();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->rider($em, 'td-owner@example.test');
        $upload = $this->approved($em, $owner);
        $uuid = $upload->getId()->toRfc4122();

        $anonymous = $client->request('GET', '/photo/'.$uuid);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $anonymous->filter('form[action$="/takedown"]')->count(), 'a stranger is not invited to ask');

        $client->loginUser($this->rider($em, 'td-stranger@example.test', 'Someone Else'));
        $stranger = $client->request('GET', '/photo/'.$uuid);
        self::assertSame(0, $stranger->filter('form[action$="/takedown"]')->count());

        $client->loginUser($owner);
        $mine = $client->request('GET', '/photo/'.$uuid);
        self::assertSame(1, $mine->filter('form[action$="/takedown"]')->count());
    }

    public function testAnonymousCannotRequestATakedown(): void
    {
        $client = $this->client();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $upload = $this->approved($em, $this->rider($em, 'td-anon@example.test'));

        $client->request('POST', '/photo/'.$upload->getId()->toRfc4122().'/takedown', ['reason' => 'x']);

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
        self::assertFalse($upload->isTakedownPending());
    }

    /**
     * One 404 for "not yours" as well as "not there": distinguishing them would
     * let anyone holding a photo URL learn whether a given account uploaded it.
     */
    public function testAStrangerCannotRequestSomebodyElsesPhotoBeRemoved(): void
    {
        $client = $this->client();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $upload = $this->approved($em, $this->rider($em, 'td-owner2@example.test'));
        $client->loginUser($this->rider($em, 'td-stranger2@example.test', 'Someone Else'));

        $client->request('POST', '/photo/'.$upload->getId()->toRfc4122().'/takedown', [
            '_token' => 'irrelevant', 'reason' => 'I would like this gone',
        ]);

        self::assertResponseStatusCodeSame(404);
        self::assertFalse($upload->isTakedownPending());
    }

    public function testTheRequestWithdrawsThePhotoAndOnlyTheUploaderIsToldWhy(): void
    {
        $client = $this->client();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->rider($em, 'td-request@example.test');
        $upload = $this->approved($em, $owner);
        $uuid = $upload->getId()->toRfc4122();

        $client->loginUser($owner);
        $this->post($client, $upload, 'I am recognisable in this one.');
        self::assertResponseRedirects('/photo/'.$uuid);

        $upload = $this->reload($em, $upload);
        self::assertTrue($upload->isTakedownPending());

        // The uploader is told their request is in hand...
        $mine = $client->followRedirect();
        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('removal request', $mine->text());

        // ...and everybody else sees the ordinary unpublished page, with no
        // hint that anyone asked for anything.
        $client->getCookieJar()->clear();
        $public = $client->request('GET', '/photo/'.$uuid);
        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('removal request', $public->text());
    }

    public function testAnEmptyReasonIsRejectedWithoutWithdrawingThePhoto(): void
    {
        $client = $this->client();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->rider($em, 'td-empty@example.test');
        $upload = $this->approved($em, $owner);

        $client->loginUser($owner);
        $this->post($client, $upload, '   ');

        $upload = $this->reload($em, $upload);
        self::assertFalse($upload->isTakedownPending());
        $client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function testAskingTwiceIsNotPossible(): void
    {
        $client = $this->client();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->rider($em, 'td-twice@example.test');
        $upload = $this->approved($em, $owner);

        $client->loginUser($owner);
        $this->post($client, $upload, 'Please remove this.');
        $upload = $this->reload($em, $upload);
        // Second-granularity: the column is TIMESTAMP(0), so a round-trip
        // through the database drops the microseconds the entity was built with.
        $first = $upload->getTakedownRequestedAt()?->format('Y-m-d H:i:s');

        $client->request('POST', '/photo/'.$upload->getId()->toRfc4122().'/takedown', [
            '_token' => 'irrelevant', 'reason' => 'Again',
        ]);

        self::assertResponseStatusCodeSame(404);
        $upload = $this->reload($em, $upload);
        self::assertSame($first, $upload->getTakedownRequestedAt()?->format('Y-m-d H:i:s'));
        self::assertSame('Please remove this.', $upload->getTakedownReason());
    }

    public function testTheCuratorDeskShowsTheRequestAndCanGrantIt(): void
    {
        $client = $this->client();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->rider($em, 'td-desk@example.test');
        $upload = $this->approved($em, $owner);

        static::getContainer()->get(MediaTakedownService::class)
            ->request($upload, 'That is me in the reflection.');

        $curator = $this->rider($em, 'td-curator@example.test', 'Curator');
        $curator->setRoles(['ROLE_CURATOR']);
        // Fully enrolled, or the 2FA enforcer bounces every elevated request
        // to /2fa/setup before the desk is ever rendered.
        $curator->setTotpSecret('JBSWY3DPEHPK3PXP');
        $curator->setTwoFaEnabled(true);
        $em->flush();
        $client->loginUser($curator);

        $desk = $client->request('GET', '/moderate');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('That is me in the reflection.', $desk->text());

        $token = (string) $desk->filter('form[action$="/moderate/takedown"] input[name="_token"]')->attr('value');
        $client->request('POST', '/moderate/takedown', [
            '_token' => $token,
            'media' => $upload->getId()->toRfc4122(),
            'decision' => 'grant',
            'note' => 'Agreed.',
        ]);
        self::assertResponseRedirects();

        $upload = $this->reload($em, $upload);
        self::assertNotNull($upload->getObjectsDeletedAt());
        self::assertFalse($upload->isTakedownPending());
    }

    public function testARiderCannotReachTheCuratorDecision(): void
    {
        $client = $this->client();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->rider($em, 'td-notcurator@example.test');
        $upload = $this->approved($em, $owner);
        static::getContainer()->get(MediaTakedownService::class)->request($upload, 'Please remove.');

        $client->loginUser($owner);
        $client->request('POST', '/moderate/takedown', [
            '_token' => 'irrelevant',
            'media' => $upload->getId()->toRfc4122(),
            'decision' => 'grant',
        ]);

        self::assertResponseStatusCodeSame(403);
        $upload = $this->reload($em, $upload);
        self::assertTrue($upload->isTakedownPending(), 'still waiting for a curator');
    }
}
