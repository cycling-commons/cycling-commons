<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Media;

use App\Catalog\Entity\Item;
use App\Catalog\Entity\Submission;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\SubmissionType;
use App\Entity\User;
use App\Media\Entity\ConsentRecord;
use App\Media\Entity\MediaModerationEvent;
use App\Media\Entity\MediaUpload;
use App\Media\MediaAction;
use App\Media\MediaConsent;
use App\Media\MediaStorage;
use App\Media\ProcessedPhoto;
use App\Moderation\ModerationService;
use App\Service\UserDeletionService;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

/**
 * Disposal (docs/specs/photo-uploads.md §6): orphans vanish, rejected media
 * leaves a tombstone once its retention window lapses, Trash takes everything
 * at once, and account deletion keeps the contribution while dropping the
 * credit.
 */
final class MediaDisposalTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private MediaStorage $storage;
    private FilesystemOperator $filesystem;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->storage = static::getContainer()->get(MediaStorage::class);
        $this->filesystem = static::getContainer()->get('media.storage.eu01');
    }

    private function rider(string $email, bool $publicProfile = true): User
    {
        $user = (new User())->setEmail($email);
        $user->setPassword('x');
        $user->setDisplayName('Disposal Rider');
        $user->setPublicProfile($publicProfile);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function stored(User $owner): MediaUpload
    {
        $consent = new ConsentRecord(Uuid::v4(), (int) $owner->getId(), MediaConsent::KIND, MediaConsent::VERSION, MediaConsent::hash('x'));
        $this->em->persist($consent);

        $upload = new MediaUpload(Uuid::v4(), (int) $owner->getId(), $consent->getId(), 'EU', 1200, 900, 4242, shard: 'EU-01', bucket: 'test-bucket-eu-01');
        $this->em->persist($upload);
        $this->em->persist(new MediaModerationEvent($upload->getId(), (int) $owner->getId(), MediaAction::Uploaded));
        $this->em->flush();

        $this->storage->store('test-bucket-eu-01', $upload->getPathPrefix(), new ProcessedPhoto('O', 'L', 'S', 1200, 900));

        return $upload;
    }

    private function backdate(MediaUpload $upload, string $interval): void
    {
        $this->em->getConnection()->executeStatement(
            "UPDATE media_upload SET created_at = NOW() - INTERVAL '{$interval}' WHERE id = ?",
            [$upload->getId()->toRfc4122()],
        );
    }

    private function runGc(): string
    {
        $tester = new CommandTester(
            (new Application(static::$kernel))->find('app:media:gc'),
        );
        $tester->execute([]);
        $this->em->clear();

        return $tester->getDisplay();
    }

    public function testAnUnclaimedUploadOlderThanSevenDaysIsCollected(): void
    {
        $rider = $this->rider('gc-orphan@example.test');
        $orphan = $this->stored($rider);
        $prefix = $orphan->getPathPrefix();
        $this->backdate($orphan, '8 days');

        $this->runGc();

        self::assertFalse($this->filesystem->fileExists($prefix.'/orig.webp'));
        self::assertNull($this->em->find(MediaUpload::class, $orphan->getId()));
        self::assertSame(0, $this->eventCount($orphan->getId()), 'nothing was ever moderated, so nothing is kept');
    }

    public function testAFreshUnclaimedUploadSurvives(): void
    {
        $rider = $this->rider('gc-fresh@example.test');
        $fresh = $this->stored($rider);

        $this->runGc();

        self::assertNotNull($this->em->find(MediaUpload::class, $fresh->getId()));
        self::assertTrue($this->filesystem->fileExists($fresh->getPathPrefix().'/orig.webp'));
    }

    public function testAClaimedPendingUploadIsNeverAnOrphan(): void
    {
        $rider = $this->rider('gc-claimed@example.test');
        $claimed = $this->stored($rider);
        $claimed->claim(4242);
        $this->em->flush();
        $this->backdate($claimed, '30 days');

        $this->runGc();

        self::assertNotNull(
            $this->em->find(MediaUpload::class, $claimed->getId()),
            'a photo waiting for a curator is not garbage, however long the wait',
        );
    }

    public function testRejectedMediaPastRetentionLosesItsObjectsButKeepsItsTombstone(): void
    {
        $rider = $this->rider('gc-rejected@example.test');
        $rejected = $this->stored($rider);
        $rejected->claim(99);
        $rejected->reject();
        $this->em->flush();
        $this->em->getConnection()->executeStatement(
            "UPDATE media_upload SET decided_at = NOW() - INTERVAL '10 years' WHERE id = ?",
            [$rejected->getId()->toRfc4122()],
        );

        $this->runGc();

        $row = $this->em->find(MediaUpload::class, $rejected->getId());
        self::assertNotNull($row, 'the row stays as an audit tombstone');
        self::assertNotNull($row->getObjectsDeletedAt());
        self::assertFalse($this->filesystem->fileExists($rejected->getPathPrefix().'/orig.webp'));
        self::assertGreaterThan(0, $this->eventCount($rejected->getId()), 'the log survives garbage collection');
    }

    public function testGarbageCollectionIsIdempotent(): void
    {
        $rider = $this->rider('gc-twice@example.test');
        $orphan = $this->stored($rider);
        $this->backdate($orphan, '9 days');

        $this->runGc();
        $second = $this->runGc();

        self::assertStringContainsString('0 orphaned', $second, 'a second run finds nothing left to do');
    }

    public function testTrashingASubmissionTakesItsPhotosImmediatelyAndCompletely(): void
    {
        $rider = $this->rider('trash-rider@example.test');
        $curator = $this->rider('trash-curator@example.test');

        $submission = (new Submission())->setType(SubmissionType::Edit)->setLetter('A')
            ->setUserId((int) $rider->getId())->setTitle('Spam')
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setChanges([])->setPayload([]);
        $this->em->persist($submission);
        $this->em->flush();

        $upload = $this->stored($rider);
        $upload->claim((int) $submission->getId());
        $this->em->flush();
        $prefix = $upload->getPathPrefix();

        static::getContainer()->get(ModerationService::class)
            ->trashSubmission((int) $submission->getId(), $curator);
        $this->em->clear();

        self::assertFalse($this->filesystem->fileExists($prefix.'/orig.webp'));
        self::assertNull($this->em->find(MediaUpload::class, $upload->getId()));
        self::assertSame(0, $this->eventCount($upload->getId()), 'Trash leaves no content behind, log included');
    }

    public function testDeletingAnAccountDropsPendingWorkAndAnonymizesApprovedPhotos(): void
    {
        $rider = $this->rider('bye@example.test');

        $item = (new Item())->setLetter('A')->setName('Fontaine')
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setState(ItemState::Unverified)->setSource(ItemSource::User)
            ->setSourceRef('sub:bye')->setAttributes([]);
        $this->em->persist($item);
        $this->em->flush();

        $pending = $this->stored($rider);
        $pendingPrefix = $pending->getPathPrefix();

        $approved = $this->stored($rider);
        $approved->claim(1);
        $approved->approve($item->getId());
        $this->em->flush();

        $item->setAttributes(['photos' => [[
            'sm' => $this->storage->url('EU-01', $approved->getPathPrefix(), 'sm'),
            'lg' => $this->storage->url('EU-01', $approved->getPathPrefix(), 'lg'),
            'credit' => 'Disposal Rider',
            'license' => 'CC BY-SA 4.0',
        ]]]);
        $this->em->flush();

        static::getContainer()->get(UserDeletionService::class)->purge($rider);
        $this->em->flush();
        $this->em->clear();

        self::assertNull($this->em->find(MediaUpload::class, $pending->getId()), 'unmoderated work goes with the account');
        self::assertFalse($this->filesystem->fileExists($pendingPrefix.'/orig.webp'));

        $stillThere = $this->em->find(MediaUpload::class, $approved->getId());
        self::assertNotNull($stillThere, 'an approved photo is a contribution to the commons and stays');
        self::assertNull($stillThere->getUserId());

        $photos = $this->em->find(Item::class, $item->getId())?->getAttributes()['photos'] ?? [];
        self::assertSame('', $photos[0]['credit'], 'the visible credit falls back to anonymous');
        self::assertSame('', $stillThere->getCreditFrozen(), 'and stays anonymous, with no account left to resolve');
    }

    public function testADepartingRiderMayChooseToKeepTheirNameOnTheirPhotos(): void
    {
        $rider = $this->rider('bye-but-credit-me@example.test');
        $rider->setKeepMediaCredit(true);
        $this->em->flush();

        $item = (new Item())->setLetter('A')->setName('Fontaine')
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setState(ItemState::Unverified)->setSource(ItemSource::User)
            ->setSourceRef('sub:credit-me')->setAttributes([]);
        $this->em->persist($item);
        $this->em->flush();

        $approved = $this->stored($rider);
        $approved->claim(1);
        $approved->approve($item->getId());
        $this->em->flush();

        $item->setAttributes(['photos' => [[
            'sm' => $this->storage->url('EU-01', $approved->getPathPrefix(), 'sm'),
            'lg' => $this->storage->url('EU-01', $approved->getPathPrefix(), 'lg'),
            'credit' => 'Disposal Rider',
            'license' => 'CC BY-SA 4.0',
        ]]]);
        $this->em->flush();

        static::getContainer()->get(UserDeletionService::class)->purge($rider);
        $this->em->flush();
        $this->em->clear();

        $row = $this->em->find(MediaUpload::class, $approved->getId());
        self::assertNotNull($row);
        self::assertNull($row->getUserId(), 'the account link goes regardless');
        self::assertSame('Disposal Rider', $row->getCreditFrozen(), 'the name they chose to keep is frozen onto the row');

        $photos = $this->em->find(Item::class, $item->getId())?->getAttributes()['photos'] ?? [];
        self::assertSame('Disposal Rider', $photos[0]['credit'], 'and keeps rendering after the account is gone');
    }

    public function testAPrivateRidersTickedBoxCannotPublishTheirNameOnTheWayOut(): void
    {
        $rider = $this->rider('bye-private@example.test', publicProfile: false);
        $rider->setKeepMediaCredit(true);   // stale: ticked while public, then went private
        $this->em->flush();

        $approved = $this->stored($rider);
        $approved->claim(1);
        $approved->approve(null);
        $this->em->flush();

        static::getContainer()->get(UserDeletionService::class)->purge($rider);
        $this->em->flush();
        $this->em->clear();

        self::assertSame(
            '',
            $this->em->find(MediaUpload::class, $approved->getId())?->getCreditFrozen(),
            'deletion may preserve a credit that was already visible — never create one',
        );
    }

    private function eventCount(Uuid $mediaId): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM media_moderation_event WHERE media_id = ?',
            [$mediaId->toRfc4122()],
        );
    }
}
