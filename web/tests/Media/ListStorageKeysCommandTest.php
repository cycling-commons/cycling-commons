<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Media;

use App\Entity\User;
use App\Media\Commons\CommonsPhotoRepository;
use App\Media\Entity\ConsentRecord;
use App\Media\Entity\MediaUpload;
use App\Media\MediaConsent;
use App\Media\MediaEscalationService;
use App\Media\MediaStorage;
use App\Media\MediaTakedownService;
use App\Media\ProcessedPhoto;
use App\Service\UserDeletionService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

/**
 * The key list a restore from the bucket backup copies back.
 *
 * Only what the database still names: a removed photo or an erased account's
 * pending work must never come back from a backup, and unscanned bytes in
 * quarantine are not backed up at all. Every fixture is written through the
 * real storage code, so a listed key that does not exist in its bucket is a
 * drift between this list and the code that writes the objects.
 */
final class ListStorageKeysCommandTest extends KernelTestCase
{
    private const string SHARD = 'test-bucket-eu-01';
    private const string PRIVATE = 'test-bucket-private';
    private const string COMMONS_FILE = 'List storage keys subject.jpg';

    private EntityManagerInterface $em;
    private Connection $db;
    private MediaStorage $storage;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->db = $this->em->getConnection();
        $this->storage = $container->get(MediaStorage::class);
        $this->db->executeStatement('DELETE FROM commons_photo WHERE file = :f', ['f' => self::COMMONS_FILE]);
    }

    public function testAPublicPhotoListsItsThreeVariantsInItsShard(): void
    {
        $rider = $this->rider('keys-public@example.test');
        $photo = $this->published($rider);

        $lines = $this->listKeys(['--user' => (string) $rider->getId()]);

        self::assertSame(self::variantLines(self::SHARD, $photo->getPathPrefix()), $lines);
        $this->assertEveryLineExists($lines);
    }

    public function testALegallyHeldPhotoIsListedUnderHeldInThePrivateBucket(): void
    {
        $rider = $this->rider('keys-held@example.test');
        $photo = $this->published($rider);
        $photo->approve(null);
        $this->em->flush();
        static::getContainer()->get(MediaEscalationService::class)->escalate($photo, $this->curator(), 'Looks like a court case.');

        $lines = $this->listKeys(['--user' => (string) $rider->getId()]);

        self::assertSame(self::variantLines(self::PRIVATE, 'held/'.$photo->getPathPrefix()), $lines);
        $this->assertEveryLineExists($lines);
    }

    public function testAPhotoWithheldByATakedownIsListedUnderHeldInThePrivateBucket(): void
    {
        $rider = $this->rider('keys-takedown@example.test');
        $photo = $this->published($rider);
        $photo->approve(null);
        $this->em->flush();
        static::getContainer()->get(MediaTakedownService::class)->request($photo, 'I changed my mind.');

        $lines = $this->listKeys(['--user' => (string) $rider->getId()]);

        self::assertSame(self::variantLines(self::PRIVATE, 'held/'.$photo->getPathPrefix()), $lines);
        $this->assertEveryLineExists($lines);
    }

    public function testUserNarrowsTheListToThatRidersPhotos(): void
    {
        $mine = $this->rider('keys-mine@example.test');
        $theirs = $this->rider('keys-theirs@example.test');
        $minePhoto = $this->published($mine);
        $theirPhoto = $this->published($theirs);

        $narrow = $this->listKeys(['--user' => (string) $mine->getId()]);
        self::assertSame(self::variantLines(self::SHARD, $minePhoto->getPathPrefix()), $narrow);

        $all = $this->listKeys();
        foreach ([$minePhoto, $theirPhoto] as $photo) {
            foreach (self::variantLines(self::SHARD, $photo->getPathPrefix()) as $line) {
                self::assertContains($line, $all, 'without --user every rider is listed');
            }
        }
    }

    public function testAQuarantinedUploadIsNeverListed(): void
    {
        $rider = $this->rider('keys-quarantine@example.test');
        $consent = $this->consent($rider);
        $upload = MediaUpload::quarantined(Uuid::v4(), (int) $rider->getId(), $consent->getId(), 'EU', self::SHARD, 4242);
        $this->em->persist($upload);
        $this->em->flush();
        $this->storage->writeQuarantine($upload->getQuarantineKey(), 'unscanned');

        self::assertSame([], $this->listKeys(['--user' => (string) $rider->getId()]));
        foreach ($this->listKeys() as $line) {
            self::assertStringNotContainsString('quarantine/', $line);
            self::assertStringNotContainsString($upload->getId()->toRfc4122(), $line);
        }
    }

    public function testAPhotoWhoseFilesWereDeletedIsNotListed(): void
    {
        $rider = $this->rider('keys-tombstone@example.test');
        $photo = $this->published($rider);
        $photo->reject();
        $photo->markObjectsDeleted();
        $this->em->flush();

        self::assertSame([], $this->listKeys(['--user' => (string) $rider->getId()]));
    }

    public function testAnErasedAccountListsNothingAndItsPendingWorkNeverComesBack(): void
    {
        $rider = $this->rider('keys-erased@example.test');
        $pending = $this->published($rider);
        $approved = $this->published($rider);
        $approved->claim(1);
        $approved->approve(null);
        $this->em->flush();
        $pendingPrefix = $pending->getPathPrefix();
        $approvedPrefix = $approved->getPathPrefix();
        $riderId = (string) $rider->getId();

        static::getContainer()->get(UserDeletionService::class)->purge($rider);
        $this->em->flush();
        $this->em->clear();

        self::assertSame([], $this->listKeys(['--user' => $riderId]), 'an erased account names no file');

        $all = $this->listKeys();
        foreach (self::variantLines(self::SHARD, $pendingPrefix) as $line) {
            self::assertNotContains($line, $all, 'pending work went with the account and must not come back');
        }
        foreach (self::variantLines(self::SHARD, $approvedPrefix) as $line) {
            self::assertContains($line, $all, 'an approved photo stays published, anonymously, under its licence');
        }
    }

    public function testACommonsPhotoIsListedForTheWholeAppOnly(): void
    {
        $prefix = $this->commonsPhoto(self::COMMONS_FILE);

        $all = $this->listKeys();
        foreach (self::variantLines(self::SHARD, $prefix) as $line) {
            self::assertContains($line, $all);
        }
        $this->assertEveryLineExists(self::variantLines(self::SHARD, $prefix));

        $rider = $this->rider('keys-commons@example.test');
        self::assertSame([], $this->listKeys(['--user' => (string) $rider->getId()]), 'a Commons file belongs to no rider');
    }

    public function testTheOutputIsSortedUniqueTabSeparatedLinesAndNothingElse(): void
    {
        $this->published($this->rider('keys-format-a@example.test'));
        $this->published($this->rider('keys-format-b@example.test'));
        $this->commonsPhoto(self::COMMONS_FILE);

        $tester = $this->runList([]);
        $stdout = $tester->getDisplay(true);

        self::assertNotSame('', $stdout);
        self::assertStringEndsWith("\n", $stdout);
        $lines = explode("\n", substr($stdout, 0, -1));
        foreach ($lines as $line) {
            self::assertMatchesRegularExpression('/^[^\t\r\n]+\t[^\t\r\n]+$/D', $line);
        }
        $sorted = $lines;
        sort($sorted, \SORT_STRING);
        self::assertSame($sorted, $lines, 'byte order, as `LC_ALL=C sort`');
        self::assertSame(array_values(array_unique($lines)), $lines);
    }

    public function testAPublishedRowWithNoBucketIsReportedOnStderrAndSkipped(): void
    {
        $rider = $this->rider('keys-no-bucket@example.test');
        $photo = $this->published($rider);
        $this->db->executeStatement("UPDATE media_upload SET storage_bucket = '' WHERE id = :id", ['id' => $photo->getId()->toRfc4122()]);

        $tester = $this->runList(['--user' => (string) $rider->getId()]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay(true));
        self::assertStringContainsString('app:media:backfill-storage-bucket', $tester->getErrorOutput(true));
    }

    public function testAKeyWithAControlCharacterFailsTheList(): void
    {
        $prefix = $this->commonsPhoto(self::COMMONS_FILE);
        $this->db->executeStatement('UPDATE commons_photo SET storage_prefix = :p WHERE file = :f', ['p' => $prefix."\tx", 'f' => self::COMMONS_FILE]);

        $tester = $this->runList([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringNotContainsString("\tx", $tester->getDisplay(true));
    }

    public function testAUserThatIsNotAnIdIsRefused(): void
    {
        $tester = $this->runList(['--user' => 'abc']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay(true));
    }

    /** @return list<string> */
    private static function variantLines(string $bucket, string $prefix): array
    {
        return [
            $bucket."\t".$prefix.'/lg.webp',
            $bucket."\t".$prefix.'/orig.webp',
            $bucket."\t".$prefix.'/sm.webp',
        ];
    }

    /** @param list<string> $lines */
    private function assertEveryLineExists(array $lines): void
    {
        $buckets = [
            self::SHARD => static::getContainer()->get('media.storage.eu01'),
            self::PRIVATE => static::getContainer()->get('media.storage.private'),
        ];
        foreach ($lines as $line) {
            [$bucket, $key] = explode("\t", $line, 2);
            $filesystem = $buckets[$bucket] ?? null;
            self::assertInstanceOf(FilesystemOperator::class, $filesystem, 'unknown bucket in '.$line);
            self::assertTrue($filesystem->fileExists($key), 'listed but not stored: '.$line);
        }
    }

    /**
     * @param array<string, string> $options
     *
     * @return list<string>
     */
    private function listKeys(array $options = []): array
    {
        $tester = $this->runList($options);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getErrorOutput(true));
        $stdout = $tester->getDisplay(true);

        return '' === $stdout ? [] : explode("\n", rtrim($stdout, "\n"));
    }

    /** @param array<string, string> $options */
    private function runList(array $options): CommandTester
    {
        $tester = new CommandTester((new Application(static::$kernel))->find('app:storage:list-keys'));
        $tester->execute($options, ['capture_stderr_separately' => true]);

        return $tester;
    }

    private function rider(string $email): User
    {
        $user = (new User())->setEmail($email)->setDisplayName('Keys Rider');
        $user->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function curator(): User
    {
        $user = (new User())->setEmail('keys-curator@example.test')->setDisplayName('Keys Curator');
        $user->setPassword('x');
        $user->setRoles(['ROLE_CURATOR']);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function consent(User $owner): ConsentRecord
    {
        $consent = new ConsentRecord(Uuid::v4(), (int) $owner->getId(), MediaConsent::KIND, MediaConsent::VERSION, MediaConsent::hash('x'));
        $this->em->persist($consent);

        return $consent;
    }

    /** A scanned photo, its three variants in the shard. */
    private function published(User $owner): MediaUpload
    {
        $consent = $this->consent($owner);
        $upload = new MediaUpload(Uuid::v4(), (int) $owner->getId(), $consent->getId(), 'EU', 1200, 900, 4242, bucket: self::SHARD);
        $this->em->persist($upload);
        $this->em->flush();
        $this->storage->store(self::SHARD, $upload->getPathPrefix(), new ProcessedPhoto('O', 'L', 'S', 1200, 900));

        return $upload;
    }

    /** A ready Commons file in the shard; returns its prefix. */
    private function commonsPhoto(string $file): string
    {
        /** @var CommonsPhotoRepository $photos */
        $photos = static::getContainer()->get(CommonsPhotoRepository::class);
        $photos->claim($file);
        $prefix = 'published/'.Uuid::v4()->toRfc4122().'/'.bin2hex(random_bytes(4));
        $this->storage->store(self::SHARD, $prefix, new ProcessedPhoto('O', 'L', 'S', 1200, 900));
        $photos->markReady($file, self::SHARD, $prefix, 'Some Author', 'Some_Author', 'CC BY-SA 4.0', 1200, 900);

        return $prefix;
    }
}
