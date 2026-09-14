<?php

// SPDX-License-Identifier: AGPL-3.0-only

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
use App\Media\PhotoFacts;
use App\Media\PhotoLocationConfirmation;
use App\Media\PhotoPlace;
use App\Media\PhotoValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * A curator confirms that a rider photo was taken at the pin
 * (docs/specs/photo-uploads.md §5g, docs/specs/scenic-views.md §8).
 *
 * The file's GPS is stripped at intake, so a photo that carried none can never
 * be measured later. A named curator vouching for the spot is what makes it
 * count as within range.
 */
final class PhotoLocationConfirmationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private int $seq = 0;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    private function user(string $email): User
    {
        $user = (new User())->setEmail($email);
        $user->setPassword('x');
        $user->setDisplayName('Location Tester');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private static function bare(): MediaUpload
    {
        return new MediaUpload(Uuid::v4(), 1, Uuid::v4(), 'EU', 1200, 900, 4242);
    }

    /** An approved photo with no GPS on a scenic view, listed in its gallery. */
    private function approvedOnScenicView(User $owner): MediaUpload
    {
        $consent = new ConsentRecord(Uuid::v4(), (int) $owner->getId(), MediaConsent::KIND, MediaConsent::VERSION, MediaConsent::hash('x'));
        $this->em->persist($consent);

        $item = (new Item())->setLetter('P')->setName('Zuiderdijk view')
            ->setGeom('{"type":"Point","coordinates":[4.9,52.4]}')->setCountryCode('NL')
            ->setSourceRef('location-confirm-test-'.++$this->seq)
            ->setSource(ItemSource::User)->setState(ItemState::Verified);
        $this->em->persist($item);
        $this->em->flush();

        $upload = new MediaUpload(Uuid::v4(), (int) $owner->getId(), $consent->getId(), 'EU', 1200, 900, 4242, bucket: 'test-bucket-eu-01');
        $this->em->persist($upload);
        $upload->approve($item->getId());
        $this->em->flush();

        $entry = static::getContainer()->get(MediaDecisionService::class)->describe($upload);
        $item->setAttributes(['type' => 'viewpoint', 'photos' => [['id' => 'someone-else', 'sm' => 'https://media.test/other.webp', 'distanceM' => 12], $entry]]);
        $this->em->flush();

        return $upload;
    }

    public function testConfirmingAnApprovedUploadOnAnItemRecordsWhoAndWhen(): void
    {
        $upload = self::bare();
        $upload->approve(77);
        $at = new \DateTimeImmutable('2026-09-14 12:00:00');

        self::assertFalse($upload->isLocationConfirmed());
        $upload->confirmLocation(5, $at, 52.4, 4.9);

        self::assertTrue($upload->isLocationConfirmed());
        self::assertSame(5, $upload->getLocationConfirmedBy());
        self::assertSame($at, $upload->getLocationConfirmedAt());
        self::assertSame([52.4, 4.9], $upload->getLocationConfirmedPin(), 'the pin it was confirmed at');
    }

    public function testAPendingUploadCannotBeConfirmed(): void
    {
        $this->expectException(\LogicException::class);
        self::bare()->confirmLocation(5, new \DateTimeImmutable(), 52.4, 4.9);
    }

    public function testAnApprovedUploadWithNoItemCannotBeConfirmed(): void
    {
        $upload = self::bare();
        $upload->approve(null);

        $this->expectException(\LogicException::class);
        $upload->confirmLocation(5, new \DateTimeImmutable(), 52.4, 4.9);
    }

    public function testTheGalleryEntryCarriesTheConfirmationOnlyWhenThereIsOne(): void
    {
        $decisions = static::getContainer()->get(MediaDecisionService::class);
        $upload = new MediaUpload(Uuid::v4(), 1, Uuid::v4(), 'EU', 1200, 900, 4242, bucket: 'test-bucket-eu-01');
        $upload->approve(77);

        self::assertArrayNotHasKey('locationConfirmed', $decisions->describe($upload));
        self::assertArrayNotHasKey('confirmedPin', $decisions->describe($upload));

        $upload->confirmLocation(5, new \DateTimeImmutable(), 52.4, 4.9);
        self::assertTrue($decisions->describe($upload)['locationConfirmed']);
        self::assertSame([52.4, 4.9], $decisions->describe($upload)['confirmedPin']);
    }

    public function testTheGalleryEntryCarriesThePinItsDistanceWasMeasuredTo(): void
    {
        $decisions = static::getContainer()->get(MediaDecisionService::class);
        $measured = new MediaUpload(Uuid::v4(), 1, Uuid::v4(), 'EU', 1200, 900, 4242, bucket: 'test-bucket-eu-01');
        $measured->resolveGps(120, 52.4, 4.9);
        $unmeasured = new MediaUpload(Uuid::v4(), 1, Uuid::v4(), 'EU', 1200, 900, 4242, bucket: 'test-bucket-eu-01');
        $unmeasured->resolveGps(null, 52.4, 4.9);

        self::assertSame(120, $decisions->describe($measured)['distanceM']);
        self::assertSame([52.4, 4.9], $decisions->describe($measured)['distancePin']);
        self::assertSame([52.4, 4.9], $measured->getGpsDistancePin());
        self::assertNull($decisions->describe($unmeasured)['distanceM']);
        self::assertArrayNotHasKey('distancePin', $decisions->describe($unmeasured), 'no distance, no pin to measure it to');
        self::assertNull($unmeasured->getGpsDistancePin());
    }

    public function testConfirmingMarksTheItemsEntryAndLogsTheAct(): void
    {
        $curator = $this->user('location-curator@example.com');
        $upload = $this->approvedOnScenicView($this->user('location-owner@example.com'));
        $itemId = (int) $upload->getItemId();
        $uuid = $upload->getId();

        static::getContainer()->get(PhotoLocationConfirmation::class)->confirm($upload, $curator);
        $this->em->clear();

        $row = $this->em->find(MediaUpload::class, $uuid);
        self::assertNotNull($row);
        self::assertSame($curator->getId(), $row->getLocationConfirmedBy());
        self::assertNotNull($row->getLocationConfirmedAt());

        $photos = $this->em->find(Item::class, $itemId)?->getAttributes()['photos'] ?? [];
        self::assertCount(2, $photos);
        self::assertArrayNotHasKey('locationConfirmed', $photos[0], 'another photo is untouched');
        self::assertTrue($photos[1]['locationConfirmed']);
        self::assertEqualsWithDelta([52.4, 4.9], $photos[1]['confirmedPin'], 1e-9, 'the item pin it was confirmed at');
        self::assertEqualsWithDelta([52.4, 4.9], $row->getLocationConfirmedPin(), 1e-9);
        self::assertTrue(PhotoValidator::verdict(PhotoFacts::fromEntry($photos[1]), new PhotoPlace('P', 52.4, 4.9))->shows(), 'the scenic view now shows it');

        $events = $this->em->getRepository(MediaModerationEvent::class)->findBy(['mediaId' => $uuid, 'action' => MediaAction::LocationConfirmed]);
        self::assertCount(1, $events);
        self::assertSame($curator->getId(), $events[0]->getActorId());
    }

    public function testConfirmingTwiceKeepsTheFirstCuratorAndLogsOnce(): void
    {
        $first = $this->user('location-first@example.com');
        $second = $this->user('location-second@example.com');
        $upload = $this->approvedOnScenicView($this->user('location-owner-2@example.com'));
        $service = static::getContainer()->get(PhotoLocationConfirmation::class);

        $service->confirm($upload, $first);
        $service->confirm($upload, $second);

        self::assertSame($first->getId(), $upload->getLocationConfirmedBy());
        self::assertCount(1, $this->em->getRepository(MediaModerationEvent::class)->findBy(['mediaId' => $upload->getId(), 'action' => MediaAction::LocationConfirmed]));
    }

    /** A confirmation vouched for the pin as it stood; at a moved pin a curator confirms again. */
    public function testConfirmingAfterThePinMovedRecordsTheNewPinAndCurator(): void
    {
        $first = $this->user('location-before-move@example.com');
        $second = $this->user('location-after-move@example.com');
        $upload = $this->approvedOnScenicView($this->user('location-owner-3@example.com'));
        $service = static::getContainer()->get(PhotoLocationConfirmation::class);
        $service->confirm($upload, $first);

        $item = $this->em->find(Item::class, (int) $upload->getItemId());
        self::assertNotNull($item);
        // About 300 m north: beyond reach of the pin it was confirmed at.
        $item->setGeom('{"type":"Point","coordinates":[4.9,52.4027]}');
        $this->em->flush();
        $entry = $item->getAttributes()['photos'][1];
        self::assertFalse(PhotoValidator::verdict(PhotoFacts::fromEntry($entry), new PhotoPlace('P', 52.4027, 4.9))->shows(), 'the moved pin hides it');

        $service->confirm($upload, $second);

        self::assertSame($second->getId(), $upload->getLocationConfirmedBy());
        self::assertEqualsWithDelta([52.4027, 4.9], $upload->getLocationConfirmedPin(), 1e-9);
        $entry = $item->getAttributes()['photos'][1];
        self::assertEqualsWithDelta([52.4027, 4.9], $entry['confirmedPin'], 1e-9);
        self::assertTrue(PhotoValidator::verdict(PhotoFacts::fromEntry($entry), new PhotoPlace('P', 52.4027, 4.9))->shows());
        self::assertCount(2, $this->em->getRepository(MediaModerationEvent::class)->findBy(['mediaId' => $upload->getId(), 'action' => MediaAction::LocationConfirmed]));
    }
}
