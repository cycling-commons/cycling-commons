<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Media;

use App\Media\Entity\ConsentRecord;
use App\Media\Entity\MediaModerationEvent;
use App\Media\Entity\MediaUpload;
use App\Media\MediaAction;
use App\Media\MediaConsent;
use App\Media\MediaStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The three media rows round-trip, and the lifecycle mutators behave the way
 * every later task assumes (docs/specs/photo-uploads.md §3, §5b).
 *
 * Test isolation: DAMA wraps each test in a rolled-back transaction.
 */
final class MediaPersistenceTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    private function consent(int $userId = 7): ConsentRecord
    {
        $record = new ConsentRecord(
            Uuid::v4(), $userId, MediaConsent::KIND, MediaConsent::VERSION,
            MediaConsent::hash('Media is licensed CC BY-SA 4.0.'),
        );
        $this->em->persist($record);
        $this->em->flush();

        return $record;
    }

    public function testConsentRecordRoundTrips(): void
    {
        $record = $this->consent();
        $id = $record->getId();
        $this->em->clear();

        $found = $this->em->find(ConsentRecord::class, $id);
        self::assertNotNull($found);
        self::assertSame(MediaConsent::KIND, $found->getKind());
        self::assertSame(MediaConsent::VERSION, $found->getVersion());
        self::assertSame(64, \strlen($found->getTextHash()), 'sha256 hex');
    }

    public function testUploadLifecycle(): void
    {
        $consent = $this->consent();
        $id = Uuid::v4();
        $upload = new MediaUpload($id, 7, $consent->getId(), 'EU', 3840, 2560, 123456, new \DateTimeImmutable('2025-10-04 09:12:33'), 50.4917, 5.8533);
        $this->em->persist($upload);
        $this->em->flush();
        $this->em->clear();

        $found = $this->em->find(MediaUpload::class, $id);
        self::assertNotNull($found);
        self::assertSame(MediaStatus::Pending, $found->getStatus());
        self::assertNull($found->getSubmissionId());
        self::assertNull($found->getDecidedAt());
        self::assertMatchesRegularExpression(
            '#^published/'.preg_quote($id->toRfc4122(), '#').'/[0-9a-f]{8}$#',
            $found->getPathPrefix(),
            'the key carries a revision, so it never changes meaning',
        );
        self::assertSame('2025-10', $found->getTakenAt()?->format('Y-m'));
        self::assertEqualsWithDelta(50.4917, (float) $found->getGpsLat(), 0.0001);

        $found->claim(42);
        $found->resolveGps(340);
        $this->em->flush();
        $this->em->clear();

        $claimed = $this->em->find(MediaUpload::class, $id);
        self::assertNotNull($claimed);
        self::assertSame(42, $claimed->getSubmissionId());
        self::assertSame(340, $claimed->getGpsDistanceM());
        self::assertNull($claimed->getGpsLat(), 'raw coordinates never survive intake');
        self::assertNull($claimed->getGpsLng(), 'raw coordinates never survive intake');

        $claimed->approve(99);
        $this->em->flush();
        $this->em->clear();

        $approved = $this->em->find(MediaUpload::class, $id);
        self::assertNotNull($approved);
        self::assertSame(MediaStatus::Approved, $approved->getStatus());
        self::assertSame(99, $approved->getItemId());
        self::assertNotNull($approved->getDecidedAt());
    }

    public function testAnonymizeDropsTheAccountLinkAndFreezesTheCredit(): void
    {
        $consent = $this->consent();
        $id = Uuid::v4();
        $upload = new MediaUpload($id, 7, $consent->getId(), 'EU', 900, 600, 4242);
        $upload->approve(99);
        $this->em->persist($upload);
        $this->em->flush();

        $upload->anonymize();
        $this->em->flush();
        $this->em->clear();

        $anonymous = $this->em->find(MediaUpload::class, $id);
        self::assertNotNull($anonymous);
        self::assertNull($anonymous->getUserId(), 'the account link goes, the photo stays');
        self::assertSame('', $anonymous->getCreditFrozen(), "'' is the departing rider choosing anonymity");
        self::assertSame(MediaStatus::Approved, $anonymous->getStatus());
    }

    public function testEventsAppendAndOrder(): void
    {
        $consent = $this->consent();
        $id = Uuid::v4();
        $this->em->persist(new MediaUpload($id, 7, $consent->getId(), 'EU', 900, 600, 4242));
        $this->em->persist(new MediaModerationEvent($id, null, MediaAction::Uploaded));
        $this->em->persist(new MediaModerationEvent($id, 7, MediaAction::Claimed));
        $this->em->persist(new MediaModerationEvent($id, 5, MediaAction::Approved, 'Nice shot'));
        $this->em->flush();
        $this->em->clear();

        $events = $this->em->getRepository(MediaModerationEvent::class)
            ->findBy(['mediaId' => $id], ['createdAt' => 'ASC', 'id' => 'ASC']);

        self::assertCount(3, $events);
        self::assertSame(
            [MediaAction::Uploaded, MediaAction::Claimed, MediaAction::Approved],
            array_map(static fn (MediaModerationEvent $e): string => $e->getAction(), $events),
        );
        self::assertNull($events[0]->getActorId(), 'a system event has no actor');
        self::assertSame('Nice shot', $events[2]->getNote());
    }

    public function testDeletingAnUploadCascadesItsEvents(): void
    {
        $consent = $this->consent();
        $id = Uuid::v4();
        $upload = new MediaUpload($id, 7, $consent->getId(), 'EU', 900, 600, 4242);
        $this->em->persist($upload);
        $this->em->persist(new MediaModerationEvent($id, null, MediaAction::Uploaded));
        $this->em->flush();

        $this->em->remove($upload);
        $this->em->flush();
        $this->em->clear();

        self::assertSame(
            0,
            (int) $this->em->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM media_moderation_event WHERE media_id = ?',
                [$id->toRfc4122()],
            ),
            'purging a row takes its events with it (docs/specs/photo-uploads.md §6 Trash/orphans)',
        );
    }
}
