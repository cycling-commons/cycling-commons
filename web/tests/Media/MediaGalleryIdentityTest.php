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
use App\Media\MediaStorage;
use App\Media\MediaTakedownService;
use App\Media\ProcessedPhoto;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Uid\Uuid;

/**
 * A gallery entry must be matched to its upload by identity, not by URL.
 *
 * Every gallery mutation used to compare the stored `sm` string against a
 * freshly built one. The address has moved twice - `photos/<uuid>/` became
 * `published/<uuid>/<rev>/`, and buckets gained their `-<cc>-<nn>` suffix - and
 * a takedown against a moved photo then matched nothing, left the image on the
 * item, and reported success. That is a GDPR Art. 17 obligation failing
 * silently, so it gets a test.
 *
 * @see docs/specs/photo-uploads.md §6b
 */
final class MediaGalleryIdentityTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private MediaTakedownService $takedowns;
    private MediaDecisionService $decisions;
    private MediaStorage $storage;
    private int $seq = 0;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->takedowns = static::getContainer()->get(MediaTakedownService::class);
        $this->decisions = static::getContainer()->get(MediaDecisionService::class);
        $this->storage = static::getContainer()->get(MediaStorage::class);
    }

    /** An approved photo attached to an item, exactly as approval leaves it. */
    private function approved(): MediaUpload
    {
        $owner = (new User())->setEmail('gallery-identity-'.++$this->seq.'@example.com');
        $owner->setPassword('x');
        $owner->setDisplayName('Gallery Rider');
        $owner->setPublicProfile(true);
        $this->em->persist($owner);
        $this->em->flush();

        $consent = new ConsentRecord(Uuid::v4(), (int) $owner->getId(), MediaConsent::KIND, MediaConsent::VERSION, MediaConsent::hash('x'));
        $this->em->persist($consent);

        $item = (new Item())->setLetter('A')->setName('Zuiderdijk')
            ->setGeom('{"type":"Point","coordinates":[5.13,52.62]}')->setCountryCode('NL')
            ->setSourceRef('gallery-identity-'.$this->seq)
            ->setSource(ItemSource::User)->setState(ItemState::Verified);
        $this->em->persist($item);
        $this->em->flush();

        $upload = new MediaUpload(Uuid::v4(), (int) $owner->getId(), $consent->getId(), 'EU', 1200, 900, 4242, bucket: 'test-bucket-eu-01');
        $this->em->persist($upload);
        $this->em->persist(new MediaModerationEvent($upload->getId(), (int) $owner->getId(), MediaAction::Uploaded));
        $upload->approve($item->getId());
        $this->em->flush();

        $this->storage->store('test-bucket-eu-01', $upload->getPathPrefix(), new ProcessedPhoto('O', 'L', 'S', 1200, 900));
        $item->setAttributes(['photos' => [$this->decisions->describe($upload)]]);
        $this->em->flush();

        return $upload;
    }

    private function itemOf(MediaUpload $upload): Item
    {
        $item = $this->em->find(Item::class, (int) $upload->getItemId());
        self::assertInstanceOf(Item::class, $item);

        return $item;
    }

    /** Rewrite the entry to the shape it had before the two address moves. */
    private function ageTheEntry(MediaUpload $upload, bool $keepId): void
    {
        $item = $this->itemOf($upload);
        $uuid = $upload->getId()->toRfc4122();
        $legacy = [
            'sm' => 'http://old-host:9100/cc-media-eu/photos/'.$uuid.'/sm.webp',
            'lg' => 'http://old-host:9100/cc-media-eu/photos/'.$uuid.'/lg.webp',
            'credit' => 'Gallery Rider',
            'license' => 'CC BY-SA 4.0',
        ];
        if ($keepId) {
            $legacy = ['id' => $uuid] + $legacy;
        }
        $item->setAttributes(['photos' => [$legacy]]);
        $this->em->flush();
    }

    public function testApprovalRecordsTheUploadIdOnTheGalleryEntry(): void
    {
        $upload = $this->approved();

        $photos = $this->itemOf($upload)->getAttributes()['photos'];
        self::assertSame($upload->getId()->toRfc4122(), $photos[0]['id']);
    }

    public function testTakedownDetachesEvenWhenTheStoredUrlHasDrifted(): void
    {
        $upload = $this->approved();
        $this->ageTheEntry($upload, keepId: true);

        $this->takedowns->request($upload, 'I am recognisable in this one.');

        // Matching on the stale `sm` would have kept the entry and reported success.
        self::assertArrayNotHasKey('photos', $this->itemOf($upload)->getAttributes());
    }

    public function testRepairRestoresTheAddressAndThenTakedownWorks(): void
    {
        $upload = $this->approved();
        $this->ageTheEntry($upload, keepId: false);

        $exit = $this->runRepair();
        self::assertSame(0, $exit);

        $this->em->clear();
        $photos = $this->itemOf($upload)->getAttributes()['photos'];
        self::assertSame($upload->getId()->toRfc4122(), $photos[0]['id']);
        self::assertSame($this->decisions->describe($upload)['sm'], $photos[0]['sm']);
        // Policy fields are the entry's own; repair rebuilds the address only.
        self::assertSame('Gallery Rider', $photos[0]['credit']);

        $upload = $this->em->find(MediaUpload::class, $upload->getId());
        self::assertInstanceOf(MediaUpload::class, $upload);
        $this->takedowns->request($upload, 'Still recognisable.');
        self::assertArrayNotHasKey('photos', $this->itemOf($upload)->getAttributes());
    }

    public function testRepairNeverRepointsOneItemsGalleryAtAnothersPhoto(): void
    {
        $mine = $this->approved();
        $theirs = $this->approved();

        // A uuid that belongs to a different item must not be adopted.
        $item = $this->itemOf($mine);
        $item->setAttributes(['photos' => [[
            'sm' => 'http://old-host:9100/cc-media-eu/photos/'.$theirs->getId()->toRfc4122().'/sm.webp',
            'credit' => '', 'license' => 'CC BY-SA 4.0',
        ]]]);
        $this->em->flush();

        $this->runRepair();
        $this->em->clear();

        $photos = $this->itemOf($mine)->getAttributes()['photos'];
        self::assertArrayNotHasKey('id', $photos[0]);
        self::assertStringContainsString('old-host', $photos[0]['sm']);
    }

    private function runRepair(): int
    {
        $app = new Application(self::$kernel);
        $app->setAutoExit(false);

        return $app->run(new ArrayInput(['command' => 'app:media:repair-galleries']), new BufferedOutput());
    }
}
