<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\ConfirmationStance;
use App\Catalog\Entity\ItemConfirmation;
use App\Catalog\Entity\Submission;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use App\Entity\User;
use App\Media\Entity\ConsentRecord;
use App\Media\Entity\MediaUpload;
use App\Media\MediaConsent;
use App\Media\MediaStorage;
use App\Media\ProcessedPhoto;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

/** app:items:purge takes an item and everything hanging off it, files included. */
final class PurgeItemsCommandTest extends KernelTestCase
{
    public function testAPurgedItemLeavesNoRowAndNoFileBehind(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $db = static::getContainer()->get(Connection::class);
        $storage = static::getContainer()->get(MediaStorage::class);

        $rider = (new User())->setEmail('purge-rider@test.test')->setDisplayName('Purge Rider');
        $rider->setPassword('x');
        $em->persist($rider);
        $em->flush();
        $uid = (int) $rider->getId();

        $itemId = (int) $db->fetchOne(
            "INSERT INTO item (letter, name, geom, country_code, state, source, source_ref, attributes, created_at, updated_at, imported_at)
             VALUES ('B', 'Purge Tap', ST_SetSRID(ST_MakePoint(6.0, 50.4), 4326), 'BE', 'unverified', 'user', 'sub:0', '{}', NOW(), NOW(), NOW())
             RETURNING id",
        );
        $sub = (new Submission())->setType(SubmissionType::NewItem)->setLetter('B')->setUserId($uid)->setItemId($itemId)
            ->setTitle('Purge Tap')->setGeom('{"type":"Point","coordinates":[6.0,50.4]}')->setCountryCode('BE')->setChanges([])->setPayload([]);
        $sub->setStatus(SubmissionStatus::Approved);
        $em->persist($sub);
        $em->persist(new ItemConfirmation($itemId, $uid, ConfirmationStance::Exists));
        $consent = new ConsentRecord(Uuid::v4(), $uid, MediaConsent::KIND, MediaConsent::VERSION, MediaConsent::hash('x'));
        $em->persist($consent);
        $upload = new MediaUpload(Uuid::v4(), $uid, $consent->getId(), 'EU', 1200, 900, 4242, bucket: 'test-bucket-eu-01');
        $upload->approve($itemId);
        $em->persist($upload);
        $em->flush();
        $storage->store('test-bucket-eu-01', $upload->getPathPrefix(), new ProcessedPhoto('O', 'L', 'S', 1200, 900));
        $prefix = $upload->getPathPrefix();
        $mediaId = $upload->getId()->toRfc4122();

        $tester = new CommandTester((new Application(self::$kernel))->find('app:items:purge'));

        // Dry run: reports, touches nothing.
        self::assertSame(0, $tester->execute(['--id' => [(string) $itemId]]));
        self::assertStringContainsString('Dry run', $tester->getDisplay());
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM item WHERE id = :id', ['id' => $itemId]));

        self::assertSame(0, $tester->execute(['--id' => [(string) $itemId], '--force' => true]));
        self::assertStringContainsString('Deleted', $tester->getDisplay());
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM item WHERE id = :id', ['id' => $itemId]));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM submission WHERE item_id = :id', ['id' => $itemId]));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM item_confirmation WHERE item_id = :id', ['id' => $itemId]));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM media_upload WHERE id = :id', ['id' => $mediaId]));
        self::assertSame(0, $storage->variantsExist('test-bucket-eu-01', $prefix), 'the stored files are gone too');
    }
}
