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
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The upload endpoint contract (docs/specs/photo-uploads.md §3): authenticated,
 * CSRF- and rate-limited, consent-gated, content-sniffed, and storing nothing at
 * all when any of those refuse.
 *
 * Test isolation: DAMA wraps each test in a rolled-back transaction; the
 * in-memory storage adapter keeps the object side hermetic.
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

    private function login(KernelBrowser $client, string $tag): User
    {
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

    private function storedCount(): int
    {
        return \count(static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(MediaUpload::class)->findAll());
    }

    public function testUploadStoresTheTrioTheRowAndTheEvent(): void
    {
        $client = static::createClient();
        $user = $this->login($client, 'ok');
        $token = $this->token($client);
        $consentId = $this->consentId($client, $token);

        $client->request(
            'POST', '/media/photos',
            ['_token' => $token, 'consentId' => $consentId, 'lat' => '50.47', 'lng' => '5.86'],
            ['photo' => $this->photoFile()],
        );
        self::assertResponseIsSuccessful();

        $data = $this->json($client);
        self::assertStringEndsWith('/sm.webp', (string) $data['sm']);
        self::assertStringEndsWith('/lg.webp', (string) $data['lg']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $row = $em->find(MediaUpload::class, Uuid::fromString((string) $data['id']));
        self::assertNotNull($row);
        self::assertSame(MediaStatus::Pending, $row->getStatus());
        self::assertSame((int) $user->getId(), $row->getUserId());
        self::assertSame($consentId, $row->getConsentRecordId()->toRfc4122());
        self::assertNull($row->getSubmissionId(), 'an upload is unclaimed until the wizard submits');
        self::assertMatchesRegularExpression('#^[A-Z]{2}$#', $row->getContinent());

        /** @var FilesystemOperator $filesystem */
        $filesystem = static::getContainer()->get('media.storage.eu');
        foreach (['orig', 'lg', 'sm'] as $variant) {
            self::assertTrue($filesystem->fileExists($row->getPathPrefix().'/'.$variant.'.webp'), $variant.' stored');
        }

        // The stored bytes state their licence and point at this photo's page,
        // and name nobody (docs/specs/photo-uploads.md §1.3c).
        $stored = new \Imagick();
        $stored->readImageBlob($filesystem->read($row->getPathPrefix().'/orig.webp'));
        $xmp = (string) $stored->getImageProfile('xmp');
        $stored->clear();
        self::assertStringContainsString('CC BY-SA 4.0', $xmp);
        self::assertStringContainsString($row->getId()->toRfc4122(), $xmp);
        self::assertStringNotContainsString('dc:creator', $xmp, 'no display name is ever embedded');
        self::assertStringNotContainsString('Upload Rider', $xmp);

        $events = $em->getRepository(MediaModerationEvent::class)->findBy(['mediaId' => $row->getId()]);
        self::assertCount(1, $events);
        self::assertSame(MediaAction::Uploaded, $events[0]->getAction());
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
            ['_token' => $token, 'consentId' => $record->getId()->toRfc4122()],
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

        $client->request('POST', '/media/photos', ['_token' => $token, 'consentId' => $consentId]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('missing_file', $this->json($client)['error']);
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
            ['_token' => $token, 'consentId' => $consentId],
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

    public function testATinyImageIsRefused(): void
    {
        $client = static::createClient();
        $this->login($client, 'tiny');
        $token = $this->token($client);
        $consentId = $this->consentId($client, $token);

        $client->request(
            'POST', '/media/photos',
            ['_token' => $token, 'consentId' => $consentId],
            ['photo' => $this->photoFile(300, 150)],
        );

        self::assertResponseStatusCodeSame(422);
        self::assertSame('photo_too_small', $this->json($client)['error']);
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
            ['_token' => $token, 'consentId' => $consentId],
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
            ['_token' => 'seeded-token', 'consentId' => $consent->getId()->toRfc4122()],
            ['photo' => $this->photoFile(400, 300)],
        );

        self::assertResponseStatusCodeSame(429);
        self::assertSame('rate_limited', $this->json($client)['error']);
        self::assertSame(0, $this->storedCount(), 'a refused upload stores nothing');
    }
}
