<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Account;

use App\Catalog\Entity\Submission;
use App\Catalog\SubmissionType;
use App\Entity\User;
use App\Media\Entity\ConsentRecord;
use App\Media\Entity\MediaModerationEvent;
use App\Media\Entity\MediaUpload;
use App\Media\MediaAction;
use App\Media\MediaConsent;
use App\Media\MediaStorage;
use App\Media\ProcessedPhoto;
use App\Messaging\Entity\UserMessage;
use App\Messaging\UserMessageKind;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * "Download my data" (docs/specs/account-and-auth.md §11): GDPR Art. 15 and
 * Art. 20 in one archive, gated on the current password, and holding one
 * rider's data and nobody else's.
 *
 * Test isolation: DAMA wraps each test in a rolled-back transaction; the
 * in-memory storage adapter keeps the object side hermetic.
 */
final class DataExportTest extends WebTestCase
{
    private const string PASSWORD = 'securepass12345!';

    /**
     * Reboot disabled, so the kernel booted here is the one that serves every
     * request. It has to be: KernelBrowser shuts the kernel down between
     * requests by default, which rebuilds the container, and in the test
     * environment the media storage is an in-memory adapter — a photo staged
     * before the export request would be served by a different, empty
     * filesystem, and the export would honestly report no file where the app
     * really has one.
     */
    private function client(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    private function makeUser(string $email): User
    {
        $container = static::getContainer();
        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Export Rider');
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
        $user = $this->makeUser("export-{$tag}@example.com");
        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->selectButton('Sign in')->form([
            '_username' => $user->getEmail(),
            '_password' => self::PASSWORD,
        ]));
        $client->followRedirect();
        self::assertResponseIsSuccessful();

        return $user;
    }

    /** Reads the CSRF token straight off the rendered settings form. */
    private function exportToken(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/account/settings');
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter('form[action$="/account/settings/export"] input[name="_token"]')->attr('value');
    }

    /** Gives the rider one of everything the export is supposed to reach. */
    private function seed(User $user): MediaUpload
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $userId = (int) $user->getId();

        $consent = new ConsentRecord(Uuid::v4(), $userId, MediaConsent::KIND, MediaConsent::VERSION, MediaConsent::hash('contract text'));
        $em->persist($consent);

        $upload = new MediaUpload(Uuid::v4(), $userId, $consent->getId(), 'EU', 1200, 900, 4242, bucket: 'test-bucket-eu-01');
        $em->persist($upload);
        $em->persist(new MediaModerationEvent($upload->getId(), $userId, MediaAction::Uploaded));

        $em->persist((new Submission())->setType(SubmissionType::Edit)->setLetter('A')
            ->setUserId($userId)->setTitle('Fontaine de la passe')
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setChanges(['name' => ['old' => 'x', 'new' => 'y']])->setPayload([]));

        $em->persist(new UserMessage(
            $userId, UserMessageKind::CuratorMessage, 'curator', 4242,
            'submission', 1, 'Fontaine de la passe', null, null, 'Thanks for this one.',
        ));

        $em->flush();

        static::getContainer()->get(MediaStorage::class)
            ->store('test-bucket-eu-01', $upload->getPathPrefix(), new ProcessedPhoto('ORIGINAL-BYTES', 'LG', 'SM', 1200, 900));

        return $upload;
    }

    /**
     * The archive has to be read out of the BUFFERED response body, not off
     * disk. HttpKernelBrowser::filterResponse() calls sendContent() on every
     * response it returns, which is exactly what fires the controller's
     * deleteFileAfterSend() — so by the time a test can look, the temp file is
     * already gone. That it is gone is the point: the export must not leave a
     * copy of somebody's account lying in the system temp directory.
     *
     * @return array{0: \ZipArchive, 1: string} the open archive, and its bytes
     */
    private function download(KernelBrowser $client, string $token): array
    {
        $client->request('POST', '/account/settings/export', ['_token' => $token, 'current_password' => self::PASSWORD]);

        $response = $client->getResponse();
        self::assertInstanceOf(BinaryFileResponse::class, $response);
        self::assertSame('application/zip', $response->headers->get('Content-Type'));
        self::assertStringContainsString('cycling-commons-export-', (string) $response->headers->get('Content-Disposition'));
        self::assertFileDoesNotExist($response->getFile()->getPathname(), 'the temp archive is deleted once sent');

        $bytes = (string) $client->getInternalResponse()->getContent();
        $path = (string) tempnam(sys_get_temp_dir(), 'cc-export-test-');
        file_put_contents($path, $bytes);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path), 'the response body is a readable ZIP archive');

        return [$zip, $bytes];
    }

    /** @return array<array-key, mixed> */
    private function entry(\ZipArchive $zip, string $name): array
    {
        $raw = $zip->getFromName($name);
        self::assertIsString($raw, "{$name} is missing from the archive");
        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testAnonymousCannotExport(): void
    {
        $client = $this->client();
        $client->request('POST', '/account/settings/export', ['_token' => 'nope']);

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testAWrongCsrfTokenExportsNothing(): void
    {
        $client = $this->client();
        $this->login($client, 'csrf');

        $client->request('POST', '/account/settings/export', ['_token' => 'wrong', 'current_password' => self::PASSWORD]);

        self::assertResponseRedirects();
        self::assertNotInstanceOf(BinaryFileResponse::class, $client->getResponse());
    }

    public function testTheWrongPasswordExportsNothing(): void
    {
        $client = $this->client();
        $this->login($client, 'password');
        $token = $this->exportToken($client);

        $client->request('POST', '/account/settings/export', ['_token' => $token, 'current_password' => 'not-my-password']);

        self::assertResponseRedirects();
        self::assertNotInstanceOf(BinaryFileResponse::class, $client->getResponse());
        $client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Current password is incorrect');
    }

    public function testAnEmptyPasswordExportsNothing(): void
    {
        $client = $this->client();
        $this->login($client, 'empty');
        $token = $this->exportToken($client);

        $client->request('POST', '/account/settings/export', ['_token' => $token, 'current_password' => '']);

        self::assertResponseRedirects();
        self::assertNotInstanceOf(BinaryFileResponse::class, $client->getResponse());
    }

    public function testTheArchiveCarriesEveryPartOfTheAccount(): void
    {
        $client = $this->client();
        $user = $this->login($client, 'full');
        $upload = $this->seed($user);
        $token = $this->exportToken($client);

        [$zip] = $this->download($client, $token);

        $account = $this->entry($zip, 'account.json');
        self::assertSame($user->getEmail(), $account['email']);
        self::assertSame('Export Rider', $account['display_name']);

        $contributions = $this->entry($zip, 'contributions.json');
        self::assertCount(1, $contributions['submissions']);
        self::assertSame('Fontaine de la passe', $contributions['submissions'][0]['title']);
        // jsonb comes back from DBAL as a string; the export must hand over a
        // real structure, not JSON quoted inside JSON.
        self::assertIsArray($contributions['submissions'][0]['changes']);

        $messages = $this->entry($zip, 'messages.json');
        self::assertCount(1, $messages);
        self::assertSame('Thanks for this one.', $messages[0]['body_text']);

        $consent = $this->entry($zip, 'consent.json');
        self::assertCount(1, $consent);
        self::assertSame(MediaConsent::VERSION, $consent[0]['version']);

        $index = $this->entry($zip, 'photos/index.json');
        self::assertCount(1, $index);
        $name = 'photos/'.$upload->getId()->toRfc4122().'.webp';
        self::assertSame($name, $index[0]['file']);
        self::assertSame('ORIGINAL-BYTES', $zip->getFromName($name));

        self::assertIsArray($this->entry($zip, 'community.json'));

        $translations = $this->entry($zip, 'translations.json');
        self::assertIsArray($translations);

        $readme = $zip->getFromName('README.txt');
        self::assertIsString($readme);
        self::assertStringContainsString('Export Rider', $readme);

        $zip->close();
    }

    public function testCredentialsAreNeverExported(): void
    {
        $client = $this->client();
        $user = $this->login($client, 'creds');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user->setTotpSecret('SUPERSECRETTOTP');
        $user->setBackupCodes(['hashed-backup-code']);
        $em->flush();

        $token = $this->exportToken($client);
        [$zip, $bytes] = $this->download($client, $token);

        $account = $this->entry($zip, 'account.json');
        self::assertArrayNotHasKey('password', $account);
        self::assertArrayNotHasKey('totp_secret', $account);
        self::assertArrayNotHasKey('backup_codes', $account);
        self::assertArrayNotHasKey('deletion_code', $account);

        // Belt and braces: the strings must not have leaked into any member,
        // not merely be absent from the one we thought to check.
        $zip->close();
        self::assertStringNotContainsString('SUPERSECRETTOTP', $bytes);
        self::assertStringNotContainsString('hashed-backup-code', $bytes);
        self::assertStringNotContainsString((string) $user->getPassword(), $bytes);
    }

    public function testAnotherRidersDataIsNotIncluded(): void
    {
        $client = $this->client();
        $mine = $this->login($client, 'mine');

        $stranger = $this->makeUser('export-stranger@example.com');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist((new Submission())->setType(SubmissionType::Edit)->setLetter('A')
            ->setUserId((int) $stranger->getId())->setTitle('Somebody else’s pin')
            ->setGeom('{"type":"Point","coordinates":[4.35,50.85]}')->setCountryCode('BE')
            ->setChanges([])->setPayload([]));
        $em->flush();

        $token = $this->exportToken($client);
        [$zip, $bytes] = $this->download($client, $token);

        self::assertSame([], $this->entry($zip, 'contributions.json')['submissions']);
        self::assertSame($mine->getEmail(), $this->entry($zip, 'account.json')['email']);

        $zip->close();
        self::assertStringNotContainsString('Somebody else', $bytes);
    }

    /**
     * A tombstoned upload keeps its row and loses its objects
     * (docs/specs/photo-uploads.md §6). The export must still list it, with a
     * null file, rather than pretend the photo never existed.
     */
    public function testATombstonedPhotoIsListedWithoutAFile(): void
    {
        $client = $this->client();
        $user = $this->login($client, 'tombstone');
        $upload = $this->seed($user);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $upload->markObjectsDeleted();
        $em->flush();

        $token = $this->exportToken($client);
        [$zip] = $this->download($client, $token);

        $index = $this->entry($zip, 'photos/index.json');
        self::assertCount(1, $index);
        self::assertNull($index[0]['file']);
        self::assertFalse($zip->getFromName('photos/'.$upload->getId()->toRfc4122().'.webp'));

        $zip->close();
    }

    /**
     * The day's allowance is exhausted by direct limiter calls and the whole
     * test then spends exactly ONE HTTP request, which has to be the first one
     * it makes. The data_export pool is the array adapter in test, and
     * Symfony's services_resetter clears every kernel.reset-tagged service at
     * the start of each top-level request after the first, so a counter can
     * never be observed accumulating across separate $client->request() calls —
     * a four-export loop here really does hand back four archives.
     * MediaUploadEndpointTest records the identical trap for media_upload.
     *
     * That is also why login and the CSRF token are set up without HTTP: a
     * GET /account/settings would spend the one request that has to be the export.
     */
    public function testAnExhaustedDailyAllowanceIsRefused(): void
    {
        $client = $this->client();
        $user = $this->makeUser('export-ratelimit@example.com');
        $client->loginUser($user);

        // A token value outside the randomized three-part form comes back from
        // CsrfTokenManager::derandomize() unchanged, so a plain seeded value
        // validates exactly as a handed-out one would.
        $session = $client->getSession();
        self::assertNotNull($session);
        $session->set('_csrf/data_export', 'seeded-token');
        $session->save();

        $limiter = static::getContainer()->get('limiter.data_export');
        $key = 'user-'.(string) $user->getId();
        for ($i = 1; $i <= 3; ++$i) {
            self::assertTrue($limiter->create($key)->consume()->isAccepted(), "export {$i} is within the day's allowance");
        }
        self::assertFalse($limiter->create($key)->consume()->isAccepted(), 'three exports a day, then no more');

        $client->request('POST', '/account/settings/export', ['_token' => 'seeded-token', 'current_password' => self::PASSWORD]);

        self::assertNotInstanceOf(BinaryFileResponse::class, $client->getResponse());
        $client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'already requested your data');
    }
}
