<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Media;

use App\Catalog\Entity\Item;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Entity\User;
use App\Media\Entity\ConsentRecord;
use App\Media\Entity\MediaModerationEvent;
use App\Media\Entity\MediaUpload;
use App\Media\MediaAction;
use App\Media\MediaConsent;
use App\Media\MediaDecisionService;
use App\Media\MediaStorage;
use App\Media\MediaTakedownService;
use App\Media\ProcessedPhoto;
use App\Messaging\Entity\UserMessage;
use App\Messaging\UserMessageKind;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Photo takedown (docs/specs/photo-uploads.md §6b): the uploader asks, the
 * photo comes down at once, a curator decides which kind of request it was.
 */
final class MediaTakedownTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private MediaTakedownService $takedowns;
    private MediaStorage $storage;
    private FilesystemOperator $filesystem;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->takedowns = static::getContainer()->get(MediaTakedownService::class);
        $this->storage = static::getContainer()->get(MediaStorage::class);
        $this->filesystem = static::getContainer()->get('media.storage.eu');
    }

    private function rider(string $email): User
    {
        $user = (new User())->setEmail($email);
        $user->setPassword('x');
        $user->setDisplayName('Takedown Rider');
        $user->setPublicProfile(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private int $itemSeq = 0;

    /** An approved photo, stored and attached to an item, exactly as approval leaves it. */
    private function approved(User $owner): MediaUpload
    {
        $consent = new ConsentRecord(Uuid::v4(), (int) $owner->getId(), MediaConsent::KIND, MediaConsent::VERSION, MediaConsent::hash('x'));
        $this->em->persist($consent);

        // uniq_item_source_ref_letter is on (source, source_ref, letter), so a
        // test that wants two items has to vary the ref.
        $item = (new Item())->setLetter('A')->setName('Fontaine de la passe')
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setSourceRef('takedown-test-'.++$this->itemSeq)
            ->setSource(ItemSource::User)->setState(ItemState::Verified);
        $this->em->persist($item);
        $this->em->flush();

        $upload = new MediaUpload(Uuid::v4(), (int) $owner->getId(), $consent->getId(), 'EU', 1200, 900, 4242);
        $this->em->persist($upload);
        $this->em->persist(new MediaModerationEvent($upload->getId(), (int) $owner->getId(), MediaAction::Uploaded));
        $upload->approve($item->getId());
        $this->em->flush();

        $this->storage->store('EU', $upload->getPathPrefix(), new ProcessedPhoto('O', 'L', 'S', 1200, 900));

        $entry = static::getContainer()->get(MediaDecisionService::class)->describe($upload);
        $item->setAttributes(['photos' => [$entry]]);
        $this->em->flush();

        return $upload;
    }

    private function itemOf(MediaUpload $upload): Item
    {
        $item = $this->em->find(Item::class, (int) $upload->getItemId());
        self::assertInstanceOf(Item::class, $item);

        return $item;
    }

    /** @return list<UserMessage> */
    private function messagesFor(User $user): array
    {
        return $this->em->getRepository(UserMessage::class)->findBy(['userId' => (int) $user->getId()]);
    }

    /** @return list<string> */
    private function actions(MediaUpload $upload): array
    {
        return array_map(
            static fn (MediaModerationEvent $e): string => $e->getAction(),
            $this->em->getRepository(MediaModerationEvent::class)->findBy(['mediaId' => $upload->getId()], ['id' => 'ASC']),
        );
    }

    public function testRequestingTakesThePhotoOffTheItemImmediately(): void
    {
        $rider = $this->rider('takedown-request@example.com');
        $upload = $this->approved($rider);
        self::assertCount(1, $this->itemOf($upload)->getAttributes()['photos']);

        $this->takedowns->request($upload, 'I am recognisable in this one.');

        self::assertTrue($upload->isTakedownPending());
        self::assertSame('I am recognisable in this one.', $upload->getTakedownReason());
        // The gallery key is dropped, not left as an empty array: map.js reads
        // `f.photos || [f.photo]` and [] is truthy.
        self::assertArrayNotHasKey('photos', $this->itemOf($upload)->getAttributes());
        self::assertContains(MediaAction::TakedownRequested, $this->actions($upload));
        // Withheld, not destroyed — the curator still has to see it.
        self::assertTrue($this->filesystem->fileExists($upload->getPathPrefix().'/sm.webp'));
    }

    public function testAnEmptyReasonIsRefused(): void
    {
        $rider = $this->rider('takedown-empty@example.com');
        $upload = $this->approved($rider);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('media.takedown.error.reason_required');
        $this->takedowns->request($upload, "   \n ");
    }

    public function testAnOverlongReasonIsRefused(): void
    {
        $rider = $this->rider('takedown-long@example.com');
        $upload = $this->approved($rider);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('media.takedown.error.reason_too_long');
        $this->takedowns->request($upload, str_repeat('a', MediaTakedownService::REASON_MAX + 1));
    }

    public function testGrantingDeletesTheObjectsAndKeepsTheRecord(): void
    {
        $rider = $this->rider('takedown-grant@example.com');
        $curator = $this->rider('takedown-curator@example.com');
        $upload = $this->approved($rider);
        $prefix = $upload->getPathPrefix();

        $this->takedowns->request($upload, 'That is me in the reflection.');
        $this->takedowns->grant($upload, $curator, 'Agreed, you are clearly visible.');

        foreach (MediaStorage::VARIANTS as $variant) {
            self::assertFalse($this->filesystem->fileExists($prefix.'/'.$variant.'.webp'), "{$variant} is gone");
        }
        // The row survives on purpose: erasing our own record of an erasure
        // request would leave us unable to show we honoured it (Art. 5(2)).
        self::assertNotNull($this->em->find(MediaUpload::class, $upload->getId()));
        self::assertNotNull($upload->getObjectsDeletedAt());
        self::assertFalse($upload->isTakedownPending());
        self::assertContains(MediaAction::TakedownGranted, $this->actions($upload));

        $messages = $this->messagesFor($rider);
        self::assertCount(1, $messages);
        self::assertSame(UserMessageKind::MediaTakedownGranted, $messages[0]->getKind());
        self::assertSame('Agreed, you are clearly visible.', $messages[0]->getBodyText());
    }

    public function testDecliningPutsThePhotoBackExactlyAsItWas(): void
    {
        $rider = $this->rider('takedown-decline@example.com');
        $curator = $this->rider('takedown-curator2@example.com');
        $upload = $this->approved($rider);
        $before = $this->itemOf($upload)->getAttributes()['photos'];

        $this->takedowns->request($upload, 'Actually I would rather keep this to myself.');
        $this->takedowns->decline($upload, $curator, 'The licence cannot be taken back, sorry.');

        self::assertFalse($upload->isTakedownPending());
        self::assertNull($upload->getTakedownRequestedAt());
        // The reason stays on the row: the next curator to look should know it
        // was asked about before.
        self::assertNotNull($upload->getTakedownReason());
        self::assertSame($before, $this->itemOf($upload)->getAttributes()['photos']);
        self::assertTrue($this->filesystem->fileExists($upload->getPathPrefix().'/sm.webp'));
        self::assertContains(MediaAction::TakedownDeclined, $this->actions($upload));

        $messages = $this->messagesFor($rider);
        self::assertCount(1, $messages);
        self::assertSame(UserMessageKind::MediaTakedownDeclined, $messages[0]->getKind());
    }

    public function testDecidingTwiceChangesNothingTheSecondTime(): void
    {
        $rider = $this->rider('takedown-twice@example.com');
        $curator = $this->rider('takedown-curator3@example.com');
        $upload = $this->approved($rider);

        $this->takedowns->request($upload, 'Take it down please.');
        $this->takedowns->grant($upload, $curator);
        $this->takedowns->grant($upload, $curator);
        $this->takedowns->decline($upload, $curator);

        self::assertCount(1, array_filter($this->actions($upload), static fn (string $a): bool => MediaAction::TakedownGranted === $a));
        self::assertNotContains(MediaAction::TakedownDeclined, $this->actions($upload));
        self::assertCount(1, $this->messagesFor($rider));
    }

    public function testTheDeskSeesPendingRequestsOldestFirst(): void
    {
        $rider = $this->rider('takedown-queue@example.com');
        $first = $this->approved($rider);
        $second = $this->approved($rider);

        self::assertSame([], $this->takedowns->pendingCards());

        $this->takedowns->request($first, 'One.');
        $this->em->getConnection()->executeStatement(
            "UPDATE media_upload SET takedown_requested_at = NOW() - INTERVAL '1 hour' WHERE id = ?",
            [$first->getId()->toRfc4122()],
        );
        $this->em->refresh($first);
        $this->takedowns->request($second, 'Two.');

        $cards = $this->takedowns->pendingCards();
        self::assertCount(2, $cards);
        self::assertSame($first->getId()->toRfc4122(), $cards[0]['uuid']);
        self::assertSame('One.', $cards[0]['reason']);
        self::assertSame('Fontaine de la passe', $cards[0]['itemName']);
    }

    /**
     * A photo whose uploader has since deleted their account is anonymized, not
     * deleted (docs/specs/photo-uploads.md §6), so user_id is null and there is
     * nobody to write to. The decision must still go through.
     */
    public function testAnAnonymizedPhotoCanStillBeDecided(): void
    {
        $rider = $this->rider('takedown-anon@example.com');
        $curator = $this->rider('takedown-curator4@example.com');
        $upload = $this->approved($rider);

        $this->takedowns->request($upload, 'Please remove.');
        $upload->anonymize('');
        $this->em->flush();

        $this->takedowns->grant($upload, $curator);

        self::assertNotNull($upload->getObjectsDeletedAt());
        self::assertSame([], $this->messagesFor($rider));
    }
}
