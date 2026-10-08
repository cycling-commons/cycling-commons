<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\User;
use App\Media\Entity\ConsentRecord;
use App\Media\Entity\MediaUpload;
use App\Media\MediaConsent;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

/**
 * app:media:rename-bucket (media-storage-architecture.md §2.1): after the
 * objects are copied into a bucket with a new name, every row that records
 * the old name is pointed at the new one. The public URL segment (the last
 * five characters) must not change, so no published link breaks.
 */
final class MediaRenameBucketCommandTest extends KernelTestCase
{
    private Connection $db;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = static::getContainer()->get(Connection::class);
        $this->db->executeStatement("DELETE FROM media_upload WHERE storage_bucket LIKE 'cc-rename-%'");
        $this->db->executeStatement("DELETE FROM commons_photo WHERE storage_bucket LIKE 'cc-rename-%'");
    }

    public function testADryRunCountsAndChangesNothing(): void
    {
        $this->seed('cc-rename-old-eu-01');

        $tester = $this->runCommand(['old' => 'cc-rename-old-eu-01', 'new' => 'cc-rename-k7q2x9-eu-01']);

        self::assertStringContainsString('1 photo upload(s) and 1 Commons photo(s)', $tester->getDisplay());
        self::assertSame(1, $this->rows('cc-rename-old-eu-01', 'media_upload'));
        self::assertSame(1, $this->rows('cc-rename-old-eu-01', 'commons_photo'));
    }

    public function testWriteMovesEveryRowToTheNewName(): void
    {
        $this->seed('cc-rename-old-eu-01');
        $this->seed('cc-rename-other-eu-02');

        $this->runCommand(['old' => 'cc-rename-old-eu-01', 'new' => 'cc-rename-k7q2x9-eu-01', '--write' => true]);

        self::assertSame(0, $this->rows('cc-rename-old-eu-01', 'media_upload'));
        self::assertSame(1, $this->rows('cc-rename-k7q2x9-eu-01', 'media_upload'));
        self::assertSame(1, $this->rows('cc-rename-k7q2x9-eu-01', 'commons_photo'));
        self::assertSame(1, $this->rows('cc-rename-other-eu-02', 'media_upload'), 'another bucket is left alone');
    }

    public function testANewNameWithAnotherUrlSegmentIsRefused(): void
    {
        $this->seed('cc-rename-old-eu-01');

        $tester = $this->runCommand(['old' => 'cc-rename-old-eu-01', 'new' => 'cc-rename-k7q2x9-eu-02', '--write' => true], Command::FAILURE);

        self::assertStringContainsString('eu-01', $tester->getDisplay());
        self::assertSame(1, $this->rows('cc-rename-old-eu-01', 'media_upload'));
    }

    public function testANameThatIsNotABucketNameIsRefused(): void
    {
        $tester = $this->runCommand(['old' => 'cc-rename-old-eu-01', 'new' => 'CC_Rename_eu-01', '--write' => true], Command::FAILURE);

        self::assertStringContainsString('not a valid bucket name', $tester->getDisplay());
    }

    private function seed(string $bucket): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('rename-'.bin2hex(random_bytes(4)).'@example.test')->setPassword('x');
        $em->persist($user);
        $em->flush();
        $userId = (int) $user->getId();
        $consent = new ConsentRecord(Uuid::v4(), $userId, MediaConsent::KIND, MediaConsent::VERSION, MediaConsent::hash('contract text'));
        $em->persist($consent);
        $em->persist(new MediaUpload(Uuid::v4(), $userId, $consent->getId(), 'EU', 1200, 900, 4242, bucket: $bucket));
        $em->flush();

        $this->db->executeStatement(
            "INSERT INTO commons_photo (file, state, credit, license, storage_bucket, storage_prefix, width, height, attempts, requested_at, ready_at)
             VALUES (:f, 'ready', 'x', 'CC BY-SA 4.0', :b, 'p/', 1200, 900, 1, NOW(), NOW())",
            ['f' => 'Rename test '.bin2hex(random_bytes(4)).'.jpg', 'b' => $bucket],
        );
    }

    private function rows(string $bucket, string $table): int
    {
        return (int) $this->db->fetchOne("SELECT count(*) FROM {$table} WHERE storage_bucket = :b", ['b' => $bucket]);
    }

    /** @param array<string, mixed> $args */
    private function runCommand(array $args, int $status = Command::SUCCESS): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:media:rename-bucket'));
        self::assertSame($status, $tester->execute($args));

        return $tester;
    }
}
