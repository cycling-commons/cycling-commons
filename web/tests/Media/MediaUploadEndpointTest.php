<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Media;

use App\Controller\MediaController;
use App\Entity\User;
use App\Media\Entity\ConsentRecord;
use App\Media\Entity\MediaModerationEvent;
use App\Media\Entity\MediaUpload;
use App\Media\MediaAction;
use App\Media\MediaConsent;
use App\Media\MediaStatus;
use App\Media\Message\ScanAndReleaseUpload;
use App\Media\Scan\ScannerUnavailable;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The upload endpoint contract (docs/specs/photo-uploads.md §3;
 * docs/specs/media-storage-architecture.md §3): authenticated, CSRF- and
 * rate-limited, consent-gated, content-sniffed, and storing nothing at all when
 * any of those refuse.
 *
 * The endpoint no longer processes anything. What it does is write the RAW
 * bytes to the private bucket and dispatch, so the assertions that matter here
 * are about the PUBLIC bucket staying empty until a worker has run.
 *
 * Test isolation: DAMA wraps each test in a rolled-back transaction; the
 * in-memory storage adapters keep both bucket sides hermetic; the in-memory
 * transport holds the message until a test decides to play the worker.
 */
final class MediaUploadEndpointTest extends WebTestCase
{
    private const string PASSWORD = 'securepass12345!';

    private function makeUser(string $email): User
    {
        $container = static::getContainer();
        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Upload Rider');
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles([]);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $em = $container->get(EntityManagerInterface::class);
        $em->persist($user);
        $em->flush();

        return $user;
    }

    /**
     * The continent chain the resolver walks for the test pin (50.47, 5.86):
     * region polygon → country BE → continent EU. There is no default
     * continent (owner 2026-08-18), so a pin outside every region refuses
     * the upload; these tests must stand on a real, resolvable region
     * exactly as production pins do.
     */
    private function seedContinentChain(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement(<<<'SQL'
            INSERT INTO world_continent (id, code, name) VALUES (990, 'EU', 'Europe') ON CONFLICT DO NOTHING;
            INSERT INTO world_country (id, iso2, iso3, name, continent_id) VALUES (991, 'BE', 'BEL', 'Belgium', 990) ON CONFLICT DO NOTHING;
            INSERT INTO region (id, slug, name, geom, area_km2, created_at, updated_at, country_code, admin_level, default_map_mode)
            VALUES (992, 'upload-square', 'Upload Square', ST_SetSRID(ST_MakeEnvelope(5.0, 50.0, 6.5, 51.0), 4326), 100, now(), now(), 'BE', 4, 'auto')
            ON CONFLICT DO NOTHING;
            SQL);
    }

    private function login(KernelBrowser $client, string $tag): User
    {
        $this->seedContinentChain();
        $user = $this->makeUser("upload-{$tag}@example.com");
        $crawler = $client->request('GET', '/login');
        $form = $crawler->selectButton('Sign in')->form([
            '_username' => $user->getEmail(),
            '_password' => self::PASSWORD,
        ]);
        $client->submit($form);
        $client->followRedirect();
        self::assertResponseIsSuccessful();

        return $user;
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        $decoded = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function token(KernelBrowser $client): string
    {
        $client->request('GET', '/media/token');

        return (string) $this->json($client)['token'];
    }

    private function consentId(KernelBrowser $client, string $token): string
    {
        $client->request('POST', '/media/consent', ['_token' => $token]);
        self::assertResponseIsSuccessful();

        return (string) $this->json($client)['consentId'];
    }

    private function photoFile(int $width = 1200, int $height = 900, string $format = 'jpeg'): UploadedFile
    {
        $image = new \Imagick();
        $image->newImage($width, $height, 'green');
        $image->setImageFormat($format);
        $path = tempnam(sys_get_temp_dir(), 'ccphoto').'.'.$format;
        $image->writeImage($path);
        $image->clear();

        return new UploadedFile($path, 'ride.'.$format, 'image/'.$format, null, true);
    }

    private function publicFs(): FilesystemOperator
    {
        /** @var FilesystemOperator $fs */
        $fs = static::getContainer()->get('media.storage.eu');

        return $fs;
    }

    private function privateFs(): FilesystemOperator
    {
        /** @var FilesystemOperator $fs */
        $fs = static::getContainer()->get('media.storage.private');

        return $fs;
    }

    /**
     * Plays the worker: takes whatever the endpoint queued and runs the real
     * handler over it.
     *
     * Must be called with no HTTP request in between. The in-memory transport
     * is kernel.reset-tagged, so the services_resetter empties it at the start
     * of every top-level request after the first, exactly as it does the rate
     * limiter's array pool (see testAnExhaustedDailyAllowanceIsACleanRefusal).
     *
     * @return list<ScanAndReleaseUpload> the messages that were handled
     */
    private function drain(): array
    {
        $transport = static::getContainer()->get('messenger.transport.async');
        $handler = static::getContainer()->get('test.media.release_handler');

        $handled = [];
        foreach ($transport->get() as $envelope) {
            $message = $envelope->getMessage();
            self::assertInstanceOf(ScanAndReleaseUpload::class, $message);
            $handler($message);
            $transport->ack($envelope);
            $handled[] = $message;
        }

        return $handled;
    }

    /**
     * The scanner the NEXT drain() will use.
     *
     * Fetched after the upload request, never before: KernelBrowser reboots
     * the kernel between requests, so a scanner grabbed earlier belongs to a
     * container that no longer exists and neither its flags nor its call count
     * would be the ones the handler sees.
     */
    private function scanner(): FakeScanner
    {
        /** @var FakeScanner $scanner */
        $scanner = static::getContainer()->get(FakeScanner::class);

        return $scanner;
    }

    /**
     * Every OBJECT in a storage, without the directory entries the in-memory
     * adapter reports beside them.
     *
     * @return list<string>
     */
    private function objectsIn(FilesystemOperator $filesystem): array
    {
        $paths = [];
        foreach ($filesystem->listContents('', true) as $item) {
            if ($item->isFile()) {
                $paths[] = $item->path();
            }
        }
        sort($paths);

        return $paths;
    }

    private function storedCount(): int
    {
        return \count(static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(MediaUpload::class)->findAll());
    }

    public function testAnUploadIsQuarantinedAndPublishesNothing(): void
    {
        $client = static::createClient();
        $user = $this->login($client, 'quarantine');
        $token = $this->token($client);
        $consentId = $this->consentId($client, $token);

        $client->request(
            'POST', '/media/photos',
            ['_token' => $token, 'consentId' => $consentId, 'lat' => '50.47', 'lng' => '5.86'],
            ['photo' => $this->photoFile()],
        );
        self::assertResponseStatusCodeSame(202, 'received, not finished');

        $data = $this->json($client);
        self::assertSame('pending_scan', $data['status']);
        self::assertFalse($data['ready']);
        self::assertArrayNotHasKey('sm', $data, 'nothing is addressable before a verdict');
        self::assertArrayNotHasKey('lg', $data);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $row = $em->find(MediaUpload::class, Uuid::fromString((string) $data['id']));
        self::assertNotNull($row);
        self::assertSame(MediaStatus::PendingScan, $row->getStatus());
        self::assertSame((int) $user->getId(), $row->getUserId());
        self::assertSame($consentId, $row->getConsentRecordId()->toRfc4122());
        self::assertNull($row->getRevision(), 'no revision means nothing published');
        self::assertMatchesRegularExpression('#^[A-Z]{2}$#', $row->getContinent());
        self::assertSame($row->getContinent(), $row->getStorageShard());

        // Exactly one private object, and NOTHING public. Asserted against the
        // buckets, not against the response body: the response is what the
        // endpoint claims, the listing is what it did.
        self::assertSame(
            ['quarantine/'.$row->getId()->toRfc4122()],
            $this->objectsIn($this->privateFs()),
        );
        self::assertSame([], $this->objectsIn($this->publicFs()));

        $events = $em->getRepository(MediaModerationEvent::class)->findBy(['mediaId' => $row->getId()]);
        self::assertCount(1, $events);
        self::assertSame(MediaAction::Uploaded, $events[0]->getAction());
    }

    public function testTheWorkerReleasesTheTrioAndEmptiesTheQuarantine(): void
    {
        $client = static::createClient();
        $this->login($client, 'release');
        $token = $this->token($client);
        $consentId = $this->consentId($client, $token);

        $client->request(
            'POST', '/media/photos',
            ['_token' => $token, 'consentId' => $consentId, 'lat' => '50.47', 'lng' => '5.86'],
            ['photo' => $this->photoFile()],
        );
        self::assertResponseStatusCodeSame(202);
        $id = (string) $this->json($client)['id'];

        $scanner = $this->scanner();
        self::assertCount(1, $this->drain());
        self::assertSame(1, $scanner->calls, 'every released photo was actually scanned');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $row = $em->find(MediaUpload::class, Uuid::fromString($id));
        self::assertNotNull($row);
        self::assertSame(MediaStatus::Pending, $row->getStatus());
        self::assertNotNull($row->getRevision());
        self::assertSame(1200, $row->getWidth(), 'the worker is what learned the dimensions');
        self::assertSame(900, $row->getHeight());
        self::assertStringStartsWith('published/'.$id.'/', $row->getPathPrefix());

        foreach (['orig', 'lg', 'sm'] as $variant) {
            self::assertTrue($this->publicFs()->fileExists($row->getPathPrefix().'/'.$variant.'.webp'), $variant.' released');
        }
        self::assertFalse(
            $this->privateFs()->fileExists('quarantine/'.$id),
            'the unscanned copy is gone once the derivatives exist',
        );

        // The released bytes state their licence and point at this photo's page,
        // and name nobody (docs/specs/photo-uploads.md §1.3c).
        $stored = new \Imagick();
        $stored->readImageBlob($this->publicFs()->read($row->getPathPrefix().'/orig.webp'));
        $xmp = (string) $stored->getImageProfile('xmp');
        $stored->clear();
        self::assertStringContainsString('CC BY-SA 4.0', $xmp);
        self::assertStringContainsString($id, $xmp);
        self::assertStringNotContainsString('dc:creator', $xmp, 'no display name is ever embedded');
        self::assertStringNotContainsString('Upload Rider', $xmp);

        $actions = array_map(
            static fn (MediaModerationEvent $e): string => $e->getAction(),
            $em->getRepository(MediaModerationEvent::class)->findBy(['mediaId' => $row->getId()]),
        );
        self::assertSame([MediaAction::Uploaded, MediaAction::Released], $actions);
    }

    public function testARedeliveredMessageAfterAReleaseChangesNothing(): void
    {
        $client = static::createClient();
        $this->login($client, 'redeliver');
        $token = $this->token($client);
        $consentId = $this->consentId($client, $token);

        $client->request(
            'POST', '/media/photos',
            ['_token' => $token, 'consentId' => $consentId, 'lat' => '50.47', 'lng' => '5.86'],
            ['photo' => $this->photoFile()],
        );
        $id = (string) $this->json($client)['id'];
        $scanner = $this->scanner();
        $handled = $this->drain();
        self::assertCount(1, $handled);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $row = $em->find(MediaUpload::class, Uuid::fromString($id));
        self::assertNotNull($row);
        $revision = $row->getRevision();

        // The same message again, exactly as Messenger would hand it back.
        $handler = static::getContainer()->get('test.media.release_handler');
        $handler($handled[0]);

        self::assertSame(1, $scanner->calls, 'a released photo is never rescanned');
        self::assertSame($revision, $row->getRevision(), 'and never republished under a new key');
        self::assertSame(MediaStatus::Pending, $row->getStatus());
    }

    public function testAnInfectedUploadIsDeletedRejectedAndNeverPublished(): void
    {
        $client = static::createClient();
        $this->login($client, 'infected');
        $token = $this->token($client);
        $consentId = $this->consentId($client, $token);

        $client->request(
            'POST', '/media/photos',
            ['_token' => $token, 'consentId' => $consentId, 'lat' => '50.47', 'lng' => '5.86'],
            ['photo' => $this->photoFile()],
        );
        self::assertResponseStatusCodeSame(202, 'the endpoint cannot know yet, and must not pretend to');
        $id = (string) $this->json($client)['id'];

        $this->scanner()->infectedWith = 'Eicar-Test-Signature';
        $this->drain();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $row = $em->find(MediaUpload::class, Uuid::fromString($id));
        self::assertNotNull($row);
        self::assertSame(MediaStatus::Rejected, $row->getStatus());
        self::assertNull($row->getRevision());
        self::assertNotNull($row->getObjectsDeletedAt());
        self::assertFalse($this->privateFs()->fileExists('quarantine/'.$id));
        self::assertSame([], $this->objectsIn($this->publicFs()));

        $events = $em->getRepository(MediaModerationEvent::class)->findBy(['mediaId' => $row->getId()]);
        $infected = array_values(array_filter($events, static fn (MediaModerationEvent $e): bool => MediaAction::ScanInfected === $e->getAction()));
        self::assertCount(1, $infected);
        self::assertSame('Eicar-Test-Signature', $infected[0]->getNote());
    }

    /**
     * The one that matters (docs/specs/media-storage-architecture.md §3.1): a
     * scanner that cannot answer must never be read as one that answered
     * "clean". The exception escapes so Messenger retries, and the bytes are
     * still there for the retry to scan.
     */
    public function testAnUnreachableScannerLeavesThePhotoQuarantinedAndRetryable(): void
    {
        $client = static::createClient();
        $this->login($client, 'noscanner');
        $token = $this->token($client);
        $consentId = $this->consentId($client, $token);

        $client->request(
            'POST', '/media/photos',
            ['_token' => $token, 'consentId' => $consentId, 'lat' => '50.47', 'lng' => '5.86'],
            ['photo' => $this->photoFile()],
        );
        $id = (string) $this->json($client)['id'];

        $this->scanner()->unavailable = true;
        $threw = false;
        try {
            $this->drain();
        } catch (ScannerUnavailable) {
            $threw = true;
        }
        self::assertTrue($threw, 'the handler must throw so Messenger retries');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $row = $em->find(MediaUpload::class, Uuid::fromString($id));
        self::assertNotNull($row);
        self::assertSame(MediaStatus::PendingScan, $row->getStatus());
        self::assertTrue($this->privateFs()->fileExists('quarantine/'.$id), 'the bytes wait for the retry');
        self::assertSame([], $this->objectsIn($this->publicFs()));
    }

    public function testUploadWithoutConsentIsRefusedAndStoresNothing(): void
    {
        $client = static::createClient();
        $this->login($client, 'noconsent');
        $token = $this->token($client);

        $client->request('POST', '/media/photos', ['_token' => $token], ['photo' => $this->photoFile()]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('consent_required', $this->json($client)['error']);
        self::assertSame(0, $this->storedCount());
    }

    public function testAnotherRidersConsentDoesNotUnlockAnUpload(): void
    {
        $client = static::createClient();
        $this->login($client, 'borrower');
        $token = $this->token($client);

        $stranger = $this->makeUser('stranger-consent@example.com');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $record = new ConsentRecord(
            Uuid::v4(), (int) $stranger->getId(),
            MediaConsent::KIND, MediaConsent::VERSION,
            MediaConsent::hash('whatever'),
        );
        $em->persist($record);
        $em->flush();

        $client->request(
            'POST', '/media/photos',
            ['_token' => $token, 'consentId' => $record->getId()->toRfc4122(), 'lat' => '50.47', 'lng' => '5.86'],
            ['photo' => $this->photoFile()],
        );

        self::assertResponseStatusCodeSame(422);
        self::assertSame('consent_required', $this->json($client)['error']);
        self::assertSame(0, $this->storedCount());
    }

    public function testAnonymousUploadIsUnauthorized(): void
    {
        $client = static::createClient();

        $client->request('POST', '/media/photos', [], ['photo' => $this->photoFile()]);

        self::assertResponseStatusCodeSame(401);
        self::assertSame(0, $this->storedCount());
    }

    public function testAForgedTokenIsForbidden(): void
    {
        $client = static::createClient();
        $this->login($client, 'badtoken');
        $consentId = $this->consentId($client, $this->token($client));

        $client->request(
            'POST', '/media/photos',
            ['_token' => 'nope', 'consentId' => $consentId],
            ['photo' => $this->photoFile()],
        );

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->storedCount());
    }

    public function testAMissingFileIsNamedNotGuessed(): void
    {
        $client = static::createClient();
        $this->login($client, 'nofile');
        $token = $this->token($client);
        $consentId = $this->consentId($client, $token);

        $client->request('POST', '/media/photos', ['_token' => $token, 'consentId' => $consentId, 'lat' => '50.47', 'lng' => '5.86']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('missing_file', $this->json($client)['error']);
    }

    /**
     * The pin is required (owner 2026-08-18): every photo is uploaded for a
     * located place, the EXIF GPS is only the second verification. A photo
     * that cannot be placed on a continent is refused, never defaulted.
     */
    public function testAnUploadWithoutAPinIsRefusedAndStoresNothing(): void
    {
        $client = static::createClient();
        $this->login($client, 'nopin');
        $token = $this->token($client);
        $consentId = $this->consentId($client, $token);

        $client->request(
            'POST', '/media/photos',
            ['_token' => $token, 'consentId' => $consentId],
            ['photo' => $this->photoFile()],
        );

        self::assertResponseStatusCodeSame(422);
        self::assertSame('missing_location', $this->json($client)['error']);
        self::assertSame(0, $this->storedCount());

        // Out-of-range coordinates are the same refusal, not a resolver guess.
        $client->request(
            'POST', '/media/photos',
            ['_token' => $token, 'consentId' => $consentId, 'lat' => '91.0', 'lng' => '5.86'],
            ['photo' => $this->photoFile()],
        );
        self::assertResponseStatusCodeSame(422);
        self::assertSame('missing_location', $this->json($client)['error']);

        // A valid pin in the middle of the Atlantic belongs to no continent:
        // refused outright, never assigned a default (owner 2026-08-18).
        $client->request(
            'POST', '/media/photos',
            ['_token' => $token, 'consentId' => $consentId, 'lat' => '0.0', 'lng' => '-30.0'],
            ['photo' => $this->photoFile()],
        );
        self::assertResponseStatusCodeSame(422);
        self::assertSame('location_unresolvable', $this->json($client)['error']);
        self::assertSame(0, $this->storedCount());
    }

    public function testAGifIsRefusedByItsContentNotItsName(): void
    {
        $client = static::createClient();
        $this->login($client, 'gif');
        $token = $this->token($client);
        $consentId = $this->consentId($client, $token);

        // A GIF as a byte literal rather than one Imagick writes for us: under
        // the shipped policy.xml (web/docker/imagemagick-policy.xml) the GIF
        // coder is denied, so this process cannot PRODUCE a GIF either and the
        // fixture line would be what failed.
        $gifBytes = base64_decode('R0lGODdhAQABAIAAAP///////ywAAAAAAQABAAACAkQBADs=', true);
        self::assertIsString($gifBytes);
        $path = tempnam(sys_get_temp_dir(), 'ccgif').'.jpg';   // a LYING extension
        file_put_contents($path, $gifBytes);

        $client->request(
            'POST', '/media/photos',
            ['_token' => $token, 'consentId' => $consentId, 'lat' => '50.47', 'lng' => '5.86'],
            ['photo' => new UploadedFile($path, 'ride.jpg', 'image/jpeg', null, true)],
        );

        self::assertResponseStatusCodeSame(422);
        // The property under test is that the lying extension buys nothing.
        // WHICH layer refuses it depends on where the suite runs, and both are
        // correct: with the shipped ImageMagick policy the GIF coder is denied
        // and the bytes never decode (photo_unreadable); without it, on a
        // developer's host, they decode and PhotoProcessor's own allowlist
        // rejects the format (photo_format). See photo-uploads.md §7a.
        self::assertContains(
            $this->json($client)['error'],
            ['photo_format', 'photo_unreadable'],
            'a GIF must be refused, by the coder policy or by our own allowlist',
        );
        self::assertSame(0, $this->storedCount());
    }

    /**
     * The decode moved, and so did the refusal
     * (docs/specs/media-storage-architecture.md §3.2). The endpoint accepts a
     * file it cannot judge without decoding; the worker is what says no. This
     * is the same shape a pixel bomb now takes: hostile input exhausts a worker
     * that gets restarted, not a web host that is serving pages.
     */
    public function testAnImageTooSmallIsAcceptedThenRefusedByTheWorker(): void
    {
        $client = static::createClient();
        $this->login($client, 'tiny');
        $token = $this->token($client);
        $consentId = $this->consentId($client, $token);

        $client->request(
            'POST', '/media/photos',
            ['_token' => $token, 'consentId' => $consentId, 'lat' => '50.47', 'lng' => '5.86'],
            ['photo' => $this->photoFile(300, 150)],
        );
        self::assertResponseStatusCodeSame(202);
        $id = (string) $this->json($client)['id'];

        $this->drain();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $row = $em->find(MediaUpload::class, Uuid::fromString($id));
        self::assertNotNull($row);
        self::assertSame(MediaStatus::Rejected, $row->getStatus());
        self::assertNull($row->getRevision());
        self::assertSame([], $this->objectsIn($this->publicFs()));

        $events = $em->getRepository(MediaModerationEvent::class)->findBy(['mediaId' => $row->getId()]);
        $refusals = array_values(array_filter($events, static fn (MediaModerationEvent $e): bool => MediaAction::ScanUnreadable === $e->getAction()));
        self::assertCount(1, $refusals);
        self::assertSame('photo_too_small', $refusals[0]->getNote());
    }

    public function testAnOversizeFileIsRefusedBeforeAnyDecoding(): void
    {
        $client = static::createClient();
        $this->login($client, 'huge');
        $token = $this->token($client);
        $consentId = $this->consentId($client, $token);

        $path = tempnam(sys_get_temp_dir(), 'ccbig').'.jpg';
        file_put_contents($path, str_repeat('x', 16 * 1024 * 1024));

        $client->request(
            'POST', '/media/photos',
            ['_token' => $token, 'consentId' => $consentId, 'lat' => '50.47', 'lng' => '5.86'],
            ['photo' => new UploadedFile($path, 'huge.jpg', 'image/jpeg', null, true)],
        );

        self::assertResponseStatusCodeSame(422);
        self::assertSame('photo_too_large', $this->json($client)['error']);
        self::assertSame(0, $this->storedCount());
    }

    /**
     * The day's allowance is exhausted by direct limiter calls and the whole
     * test then spends exactly ONE HTTP request, which has to be the first one
     * it makes. Both halves of that are forced by the same mechanism: the
     * media_upload pool is the array adapter in test, and Symfony's
     * services_resetter clears every kernel.reset-tagged service at the start
     * of each top-level request after the first — so a counter can never be
     * observed accumulating across separate $client->request() calls
     * (RouteSuggestFlowTest records the identical trap for route_suggest, and a
     * 31-upload loop here really does return 200 thirty-one times). Direct
     * calls do not go through Kernel::handle(), so they survive, and
     * KernelBrowser deliberately does not shut the kernel down before its first
     * request — which is the window this test writes into.
     *
     * That constraint is why login and consent are set up without HTTP, and why
     * the CSRF token is seeded straight into the session: a GET /media/token
     * would spend the one request that has to be the upload.
     */
    public function testAnExhaustedDailyAllowanceIsACleanRefusal(): void
    {
        $client = static::createClient();
        $user = $this->makeUser('upload-limit@example.com');
        $client->loginUser($user);

        // A token value that is not in the randomized three-part form comes back
        // from CsrfTokenManager::derandomize() unchanged, so a plain seeded
        // value validates exactly as a handed-out one would.
        $session = $client->getSession();
        self::assertNotNull($session);
        $session->set('_csrf/'.MediaController::CSRF_INTENTION, 'seeded-token');
        $session->save();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $consent = new ConsentRecord(
            Uuid::v4(), (int) $user->getId(),
            MediaConsent::KIND, MediaConsent::VERSION, MediaConsent::hash('contract'),
        );
        $em->persist($consent);
        $em->flush();

        $limiter = static::getContainer()->get('limiter.media_upload');
        $key = 'user-'.(string) $user->getId();
        for ($i = 1; $i <= 30; ++$i) {
            self::assertTrue($limiter->create($key)->consume()->isAccepted(), "upload {$i} is within the day's allowance");
        }
        self::assertFalse($limiter->create($key)->consume()->isAccepted(), '30 uploads a day, then no more');

        $client->request(
            'POST', '/media/photos',
            ['_token' => 'seeded-token', 'consentId' => $consent->getId()->toRfc4122(), 'lat' => '50.47', 'lng' => '5.86'],
            ['photo' => $this->photoFile(400, 300)],
        );

        self::assertResponseStatusCodeSame(429);
        self::assertSame('rate_limited', $this->json($client)['error']);
        self::assertSame(0, $this->storedCount(), 'a refused upload stores nothing');
    }
}
