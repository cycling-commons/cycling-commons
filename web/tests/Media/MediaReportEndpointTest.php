<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Media;

use App\Entity\User;
use App\Media\Entity\ConsentRecord;
use App\Media\Entity\MediaUpload;
use App\Media\MediaConsent;
use App\Media\MediaTakedownCategory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;

/**
 * The third-party report endpoints (docs/specs/photo-uploads.md §6c): open to
 * everyone, an oracle to no one. The properties under test are the response
 * ones — a signed-out visitor can file, and every outcome short of a
 * validation error looks identical from outside.
 */
final class MediaReportEndpointTest extends WebTestCase
{
    /** KernelBrowser rebuilds the container between requests otherwise. */
    private function client(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    private function rider(EntityManagerInterface $em, string $email): User
    {
        $user = (new User())->setEmail($email);
        $user->setPassword('x');
        $user->setDisplayName('Report Endpoint Rider');
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

        $upload = new MediaUpload(Uuid::v4(), $ownerId, $consent->getId(), 'EU', 1200, 900, 4242, bucket: 'test-bucket-eu-01');
        $em->persist($upload);
        $upload->claim(1);
        $upload->approve(null);
        $em->flush();

        return $upload;
    }

    /** See MediaTakedownEndpointTest::reload() — the EM is kernel.reset-tagged. */
    private function reload(EntityManagerInterface $em, MediaUpload $upload): MediaUpload
    {
        $fresh = $em->find(MediaUpload::class, $upload->getId());
        self::assertInstanceOf(MediaUpload::class, $fresh);

        return $fresh;
    }

    private function token(Crawler $crawler): string
    {
        return (string) $crawler->filter('input[name="_token"]')->attr('value');
    }

    /** @param array<string, string> $overrides */
    private function file(KernelBrowser $client, string $uuid, array $overrides = []): void
    {
        $crawler = $client->request('GET', '/photo/'.$uuid.'/report');
        $client->request('POST', '/photo/'.$uuid.'/report', $overrides + [
            '_token' => $this->token($crawler),
            'category' => MediaTakedownCategory::IdentifiableSelf,
            'reason' => 'That is me in the picture.',
            'contact' => '',
        ]);
    }

    public function testASignedOutVisitorCanFileAndThePhotoStaysUp(): void
    {
        $client = $this->client();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $upload = $this->approved($em, $this->rider($em, 're-file@example.test'));
        $uuid = $upload->getId()->toRfc4122();

        $this->file($client, $uuid);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Report received');

        $fresh = $this->reload($em, $upload);
        self::assertTrue($fresh->isTakedownPending(), 'queued for a curator');
        self::assertFalse($fresh->isTakedownWithheld(), 'and the photo is untouched');

        // The photo page still serves it — filing changed nothing visible.
        $client->request('GET', '/photo/'.$uuid);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.photo-page');
    }

    public function testEveryOutcomeAnswersIdentically(): void
    {
        $client = $this->client();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $upload = $this->approved($em, $this->rider($em, 're-oracle@example.test'));

        // A photo that exists…
        $this->file($client, $upload->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        $real = (string) $client->getResponse()->getContent();

        // …a uuid that never existed…
        $this->file($client, Uuid::v4()->toRfc4122());
        self::assertResponseIsSuccessful();
        $invented = (string) $client->getResponse()->getContent();

        // …and a photo already reported (the slot is taken).
        $this->file($client, $upload->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        $repeat = (string) $client->getResponse()->getContent();

        // Compare the bodies with the uuid and every per-render secret
        // normalised out. Everything else must be byte-identical, or the form
        // is an oracle.
        //
        // The floating bug button (contact-and-support.md §5) renders on this
        // page like every other and brings its own CSRF token, form stamp and
        // proof-of-work challenge, all freshly random per request. None of them
        // is derived from the uuid or from whether the photo exists, so they
        // are normalised for the same reason the form's own token always was.
        $normalise = static function (string $html): string {
            $html = (string) preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', 'UUID', $html);
            $html = (string) preg_replace('/name="_token" value="[^"]*"/', 'TOKEN', $html);
            $html = (string) preg_replace('/data-token="[^"]*"/', 'DATA-TOKEN', $html);
            $html = (string) preg_replace('/data-stamp="[^"]*"/', 'DATA-STAMP', $html);
            $html = (string) preg_replace('/data-pow-challenge="[^"]*"/', 'DATA-POW', $html);
            $html = (string) preg_replace('/name="pow_challenge" value="[^"]*"/', 'POW', $html);

            return (string) preg_replace('/nonce="[^"]*"/', 'NONCE', $html);
        };
        self::assertSame($normalise($real), $normalise($invented));
        self::assertSame($normalise($real), $normalise($repeat));
    }

    public function testTheFormRendersForAnInventedUuidToo(): void
    {
        $client = $this->client();

        $client->request('GET', '/photo/'.Uuid::v4()->toRfc4122().'/report');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
    }

    public function testValidationErrorsSurfaceInsteadOfPretendingSuccess(): void
    {
        $client = $this->client();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $upload = $this->approved($em, $this->rider($em, 're-validate@example.test'));
        $uuid = $upload->getId()->toRfc4122();

        $this->file($client, $uuid, ['reason' => '   ']);
        self::assertResponseStatusCodeSame(422);

        $this->file($client, $uuid, ['contact' => 'not-an-address']);
        self::assertResponseStatusCodeSame(422);

        $this->file($client, $uuid, ['category' => 'invented_category']);
        self::assertResponseStatusCodeSame(422);

        self::assertFalse($this->reload($em, $upload)->isTakedownPending());
    }

    /**
     * The allowance is exhausted by direct limiter calls and the test then
     * spends exactly ONE HTTP request — the array-adapter pool is cleared by
     * services_resetter at the start of every top-level request after the
     * first, so a counter can never be observed accumulating over HTTP
     * (MediaUploadEndpointTest documents the same trap for media_upload).
     * The CSRF token is seeded straight into the session for the same reason:
     * a GET for the form would spend the one request that has to be the POST.
     */
    public function testTheGeneralLimiterBindsAtFivePerDay(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->rider($em, 're-limit@example.test');
        $upload = $this->approved($em, $owner);
        $client->loginUser($owner);

        $session = $client->getSession();
        self::assertNotNull($session);
        $session->set('_csrf/photo_report', 'seeded-token');
        $session->save();

        $limiter = static::getContainer()->get('limiter.media_report');
        for ($i = 1; $i <= 5; ++$i) {
            self::assertTrue($limiter->create('ip-127.0.0.1')->consume()->isAccepted(), "report {$i} is within the day's allowance");
        }

        $client->request('POST', '/photo/'.$upload->getId()->toRfc4122().'/report', [
            '_token' => 'seeded-token',
            'category' => MediaTakedownCategory::IdentifiableSelf,
            'reason' => 'That is me.',
            'contact' => '',
        ]);

        self::assertResponseStatusCodeSame(429);
        self::assertFalse($this->reload($em, $upload)->isTakedownPending(), 'a rate-limited report records nothing');
    }

    /** Same one-request discipline as above — the urgent lever's budget is one pull per IP per day. */
    public function testTheUrgentLimiterBindsAtOnePerDay(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->rider($em, 're-urgent-limit@example.test');
        $upload = $this->approved($em, $owner);
        $client->loginUser($owner);

        $session = $client->getSession();
        self::assertNotNull($session);
        $session->set('_csrf/photo_report', 'seeded-token');
        $session->save();

        $urgent = static::getContainer()->get('limiter.media_report_urgent');
        self::assertTrue($urgent->create('ip-127.0.0.1')->consume()->isAccepted(), 'the first pull of the lever');

        $client->request('POST', '/photo/'.$upload->getId()->toRfc4122().'/report', [
            '_token' => 'seeded-token',
            'category' => MediaTakedownCategory::IntimateOrChild,
            'reason' => 'Same again.',
            'contact' => '',
        ]);

        self::assertResponseStatusCodeSame(429);
        self::assertFalse($this->reload($em, $upload)->isTakedownPending(), 'the second pull of the lever recorded nothing');
    }

    public function testThePhotoPageLinksTheReportRouteForEveryone(): void
    {
        $client = $this->client();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $upload = $this->approved($em, $this->rider($em, 're-link@example.test'));
        $uuid = $upload->getId()->toRfc4122();

        $crawler = $client->request('GET', '/photo/'.$uuid);

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('a[href$="/'.$uuid.'/report"]')->count(), 'a signed-out stranger sees the report link');
    }
}
