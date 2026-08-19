<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Media;

use App\Catalog\Entity\ChangeHistory;
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
use App\Media\MediaStatus;
use App\Moderation\ModerationScopeProvider;
use App\Moderation\ModerationService;
use App\Moderation\SubmissionQueue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The moderation path for photos (docs/specs/photo-uploads.md §5, §5b): the
 * curator sees pending photos with the facts harvested from them, decides them
 * individually, approval attaches them to the item in the shape the drawer
 * already renders, and every transition lands in the append-only log.
 */
final class MediaModerationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ModerationService $moderation;
    private User $curator;
    private User $rider;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->moderation = static::getContainer()->get(ModerationService::class);

        $this->curator = (new User())->setEmail('curator@media.test');
        $this->curator->setPassword('x');
        $this->em->persist($this->curator);

        $this->rider = (new User())->setEmail('rider@media.test');
        $this->rider->setPassword('x');
        $this->rider->setDisplayName('Marta Verhoeven');
        $this->rider->setPublicProfile(true);
        $this->em->persist($this->rider);
        $this->em->flush();
    }

    /** @return array{Item, Submission} */
    private function seedEdit(): array
    {
        $item = (new Item())->setLetter('A')->setName('Fontaine du test')
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setState(ItemState::Unverified)->setSource(ItemSource::Osm)
            ->setSourceRef('node/media-'.bin2hex(random_bytes(4)))
            ->setAttributes([]);
        $this->em->persist($item);
        $this->em->flush();

        $submission = (new Submission())->setType(SubmissionType::Edit)->setLetter('A')
            ->setUserId((int) $this->rider->getId())->setItemId($item->getId())
            ->setTitle('Fontaine du test')
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setChanges([])->setPayload([]);
        $this->em->persist($submission);
        $this->em->flush();

        return [$item, $submission];
    }

    private function claimedUpload(Submission $submission, ?\DateTimeImmutable $takenAt = null, ?int $distanceM = null): MediaUpload
    {
        $consent = new ConsentRecord(
            Uuid::v4(), (int) $this->rider->getId(),
            MediaConsent::KIND, MediaConsent::VERSION, MediaConsent::hash('x'),
        );
        $this->em->persist($consent);

        $upload = new MediaUpload(
            Uuid::v4(), (int) $this->rider->getId(), $consent->getId(), 'EU',
            1200, 900, 4242, $takenAt, shard: 'EU-01');
        $upload->claim((int) $submission->getId());
        $upload->resolveGps($distanceM);
        $this->em->persist($upload);
        $this->em->flush();

        return $upload;
    }

    public function testApprovalAttachesThePhotosInTheShapeTheDrawerRenders(): void
    {
        [$item, $submission] = $this->seedEdit();
        $upload = $this->claimedUpload($submission, new \DateTimeImmutable('2025-10-04 09:12:33'));

        $this->moderation->decide((int) $submission->getId(), 'approve', $this->curator, null);
        $this->em->clear();

        $reloaded = $this->em->find(Item::class, $item->getId());
        self::assertNotNull($reloaded);
        $photos = $reloaded->getAttributes()['photos'] ?? null;
        self::assertIsArray($photos);
        self::assertCount(1, $photos);
        self::assertStringEndsWith('/sm.webp', $photos[0]['sm']);
        self::assertStringEndsWith('/lg.webp', $photos[0]['lg']);
        self::assertSame('CC BY-SA 4.0', $photos[0]['license']);
        self::assertSame('Marta Verhoeven', $photos[0]['credit']);
        self::assertSame('2025-10', $photos[0]['takenAt'], 'month granularity, never a precise timestamp');

        $row = $this->em->find(MediaUpload::class, $upload->getId());
        self::assertNotNull($row);
        self::assertSame(MediaStatus::Approved, $row->getStatus());
        self::assertSame($item->getId(), $row->getItemId());
        self::assertNotNull($row->getDecidedAt());
    }

    public function testAPhotoWithoutACaptureDateOmitsTheKeyEntirely(): void
    {
        [$item, $submission] = $this->seedEdit();
        $this->claimedUpload($submission);

        $this->moderation->decide((int) $submission->getId(), 'approve', $this->curator, null);
        $this->em->clear();

        $photos = $this->em->find(Item::class, $item->getId())?->getAttributes()['photos'] ?? [];
        self::assertArrayNotHasKey('takenAt', $photos[0]);
    }

    public function testAPrivateProfileCreditsAnonymously(): void
    {
        $this->rider->setPublicProfile(false);
        $this->em->flush();

        [$item, $submission] = $this->seedEdit();
        $this->claimedUpload($submission);

        $this->moderation->decide((int) $submission->getId(), 'approve', $this->curator, null);
        $this->em->clear();

        $photos = $this->em->find(Item::class, $item->getId())?->getAttributes()['photos'] ?? [];
        self::assertSame('', $photos[0]['credit']);
    }

    public function testOnePhotoCanBeRejectedWhileTheFactsAreApproved(): void
    {
        [$item, $submission] = $this->seedEdit();
        $keep = $this->claimedUpload($submission);
        $drop = $this->claimedUpload($submission);

        $this->moderation->decide(
            (int) $submission->getId(), 'approve', $this->curator, 'The second one is not yours.',
            [$drop->getId()->toRfc4122()],
        );
        $this->em->clear();

        self::assertSame(MediaStatus::Approved, $this->em->find(MediaUpload::class, $keep->getId())?->getStatus());
        self::assertSame(MediaStatus::Rejected, $this->em->find(MediaUpload::class, $drop->getId())?->getStatus());

        $photos = $this->em->find(Item::class, $item->getId())?->getAttributes()['photos'] ?? [];
        self::assertCount(1, $photos, 'only the kept photo reaches the item');
    }

    public function testARejectedSubmissionRejectsEveryPhotoAndTouchesNoItem(): void
    {
        [$item, $submission] = $this->seedEdit();
        $one = $this->claimedUpload($submission);
        $two = $this->claimedUpload($submission);

        $this->moderation->decide((int) $submission->getId(), 'reject', $this->curator, null);
        $this->em->clear();

        self::assertSame(MediaStatus::Rejected, $this->em->find(MediaUpload::class, $one->getId())?->getStatus());
        self::assertSame(MediaStatus::Rejected, $this->em->find(MediaUpload::class, $two->getId())?->getStatus());
        self::assertArrayNotHasKey('photos', $this->em->find(Item::class, $item->getId())?->getAttributes() ?? []);
    }

    public function testARejectedSubmissionHasNoPerPhotoEscape(): void
    {
        [, $submission] = $this->seedEdit();
        $spared = $this->claimedUpload($submission);

        // The curator ticked "keep" — but rejecting the submission rejects all
        // of its photos, with no per-photo escape (docs/specs/photo-uploads.md §5).
        $this->moderation->decide((int) $submission->getId(), 'reject', $this->curator, null, []);
        $this->em->clear();

        self::assertSame(MediaStatus::Rejected, $this->em->find(MediaUpload::class, $spared->getId())?->getStatus());
    }

    public function testNeedsInfoLeavesPhotosPending(): void
    {
        [, $submission] = $this->seedEdit();
        $upload = $this->claimedUpload($submission);

        $this->moderation->decide((int) $submission->getId(), 'needs_info', $this->curator, 'Whose photo is this?');
        $this->em->clear();

        self::assertSame(MediaStatus::Pending, $this->em->find(MediaUpload::class, $upload->getId())?->getStatus());
    }

    public function testApprovalAppendsToAnExistingGalleryAndMigratesTheLegacySingular(): void
    {
        [$item, $submission] = $this->seedEdit();
        $item->setAttributes(['photo' => ['sm' => '/media/old-sm.jpg', 'lg' => '/media/old.jpg', 'credit' => 'Someone', 'license' => 'CC BY-SA 4.0']]);
        $this->em->flush();
        $this->claimedUpload($submission);

        $this->moderation->decide((int) $submission->getId(), 'approve', $this->curator, null);
        $this->em->clear();

        $attributes = $this->em->find(Item::class, $item->getId())?->getAttributes() ?? [];
        self::assertArrayNotHasKey('photo', $attributes, 'the singular is migrated into the gallery, not left to shadow it');
        self::assertCount(2, $attributes['photos']);
        self::assertSame('/media/old-sm.jpg', $attributes['photos'][0]['sm'], 'the existing photo keeps its place');
    }

    public function testEveryDecisionLandsInTheAppendOnlyLogAndItemHistory(): void
    {
        [$item, $submission] = $this->seedEdit();
        $upload = $this->claimedUpload($submission);

        $this->moderation->decide((int) $submission->getId(), 'approve', $this->curator, 'Lovely light');
        $this->em->clear();

        $events = $this->em->getRepository(MediaModerationEvent::class)->findBy(['mediaId' => $upload->getId()]);
        $approved = array_values(array_filter(
            $events,
            static fn (MediaModerationEvent $e): bool => MediaAction::Approved === $e->getAction(),
        ));
        self::assertCount(1, $approved);
        self::assertSame((int) $this->curator->getId(), $approved[0]->getActorId());
        self::assertSame('Lovely light', $approved[0]->getNote());

        $history = $this->em->getRepository(ChangeHistory::class)->findBy(['itemId' => $item->getId(), 'field' => 'photos']);
        self::assertCount(1, $history, 'the item side records the attachment through the normal moderation path');
    }

    public function testTheQueueRowCarriesThePendingPhotosWithTheirHarvestedFacts(): void
    {
        [, $submission] = $this->seedEdit();
        $upload = $this->claimedUpload($submission, new \DateTimeImmutable('2025-10-04 09:12:33'), 340);

        $queue = static::getContainer()->get(SubmissionQueue::class);
        $rows = $queue->filtered(
            static::getContainer()->get(ModerationScopeProvider::class)->scopeFor($this->curator),
            null, null, null,
        );

        $row = null;
        foreach ($rows as $candidate) {
            if ($candidate['id'] === (int) $submission->getId()) {
                $row = $candidate;
                break;
            }
        }

        self::assertNotNull($row, 'the seeded submission is in the queue');
        self::assertCount(1, $row['photos']);
        self::assertSame($upload->getId()->toRfc4122(), $row['photos'][0]['id']);
        self::assertStringEndsWith('/sm.webp', $row['photos'][0]['sm']);
        self::assertSame('2025-10', $row['photos'][0]['takenAt']);
        self::assertSame(340, $row['photos'][0]['distanceM']);
    }
}
