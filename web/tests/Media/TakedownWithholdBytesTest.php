<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Media;

use App\Catalog\Entity\Item;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Entity\User;
use App\Media\Entity\ConsentRecord;
use App\Media\Entity\MediaUpload;
use App\Media\MediaConsent;
use App\Media\MediaDecisionService;
use App\Media\MediaStorage;
use App\Media\MediaTakedownService;
use App\Media\ProcessedPhoto;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * "Withheld immediately" withholds the image, not only its place in the
 * gallery. An approved photo's URL was public, sits in cached tiles and in
 * the proxy, so unlinking alone left the bytes one request away; they move to
 * the private bucket, and come back only when a curator restores the photo
 * (audit 2026-10-04).
 *
 * @see docs/specs/photo-uploads.md §6b, §6c
 */
final class TakedownWithholdBytesTest extends KernelTestCase
{
    private const string BUCKET = 'test-bucket-eu-01';

    private EntityManagerInterface $em;
    private MediaTakedownService $takedowns;
    private MediaStorage $storage;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->takedowns = static::getContainer()->get(MediaTakedownService::class);
        $this->storage = static::getContainer()->get(MediaStorage::class);
    }

    private function approved(string $email): MediaUpload
    {
        $owner = (new User())->setEmail($email)->setDisplayName('Withhold Rider');
        $owner->setPassword('x');
        $this->em->persist($owner);
        $this->em->flush();
        $consent = new ConsentRecord(Uuid::v4(), (int) $owner->getId(), MediaConsent::KIND, MediaConsent::VERSION, MediaConsent::hash('x'));
        $this->em->persist($consent);
        $item = (new Item())->setLetter('A')->setName('Fontaine retenue')
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setSourceRef('withhold-bytes-'.$email)
            ->setSource(ItemSource::User)->setState(ItemState::Verified);
        $this->em->persist($item);
        $this->em->flush();

        $upload = new MediaUpload(Uuid::v4(), (int) $owner->getId(), $consent->getId(), 'EU', 1200, 900, 4242, bucket: self::BUCKET);
        $this->em->persist($upload);
        $upload->approve($item->getId());
        $this->em->flush();
        $this->storage->store(self::BUCKET, $upload->getPathPrefix(), new ProcessedPhoto('O', 'L', 'S', 1200, 900));
        $item->setAttributes(['photos' => [static::getContainer()->get(MediaDecisionService::class)->describe($upload)]]);
        $this->em->flush();

        return $upload;
    }

    private function curator(): User
    {
        $u = (new User())->setEmail('withhold-curator@example.test')->setDisplayName('Withhold Curator');
        $u->setPassword('x');
        $u->setRoles(['ROLE_CURATOR']);
        $this->em->persist($u);
        $this->em->flush();

        return $u;
    }

    public function testTheUploadersOwnRequestTakesTheBytesOffThePublicBucket(): void
    {
        $upload = $this->approved('withhold-own@example.test');

        $this->takedowns->request($upload, 'Please take it down.');

        self::assertSame(0, $this->storage->variantsExist(self::BUCKET, $upload->getPathPrefix()), 'nothing public is left');
        self::assertSame(3, $this->storage->heldVariantsExist($upload->getPathPrefix()), 'the private copy is whole');
    }

    public function testAnUrgentReportTakesTheBytesOffThePublicBucket(): void
    {
        $upload = $this->approved('withhold-urgent@example.test');

        $this->takedowns->report($upload, 'intimate_or_child', 'This is me, without consent.', null, '203.0.113.20');

        self::assertTrue($upload->isTakedownWithheld());
        self::assertSame(0, $this->storage->variantsExist(self::BUCKET, $upload->getPathPrefix()));
        self::assertSame(3, $this->storage->heldVariantsExist($upload->getPathPrefix()));
    }

    public function testTheDeskCardShowsTheHeldCopyThroughTheCuratorView(): void
    {
        $upload = $this->approved('withhold-card@example.test');
        $this->takedowns->request($upload, 'Card check.');

        $cards = array_values(array_filter($this->takedowns->pendingCards(), static fn (array $c): bool => $c['uuid'] === $upload->getId()->toRfc4122()));
        self::assertCount(1, $cards);
        self::assertSame('/moderate/withheld-photo/'.$upload->getId()->toRfc4122(), $cards[0]['sm']);
        self::assertSame('/moderate/withheld-photo/'.$upload->getId()->toRfc4122().'/lg', $cards[0]['lg'], 'a click opens the large one');
        $stream = $this->takedowns->withheldStream($upload);
        self::assertIsResource($stream, 'the view reads the private copy');
        self::assertSame('S', stream_get_contents($stream));
        $large = $this->takedowns->withheldStream($upload, 'lg');
        self::assertIsResource($large);
        self::assertSame('L', stream_get_contents($large));
        self::assertNull($this->takedowns->withheldStream($upload, 'orig'), 'never the original');
    }

    public function testADeclineBringsTheBytesBack(): void
    {
        $upload = $this->approved('withhold-decline@example.test');
        $this->takedowns->request($upload, 'Second thoughts.');

        $this->takedowns->decline($upload, $this->curator(), 'It shows the fountain only.');

        self::assertSame(3, $this->storage->variantsExist(self::BUCKET, $upload->getPathPrefix()), 'public again with the photo');
        self::assertSame(0, $this->storage->heldVariantsExist($upload->getPathPrefix()));
    }

    public function testAGrantRemovesTheHeldCopyToo(): void
    {
        $upload = $this->approved('withhold-grant@example.test');
        $this->takedowns->request($upload, 'Please remove it.');

        $this->takedowns->grant($upload, $this->curator());

        self::assertNotNull($upload->getObjectsDeletedAt());
        self::assertSame(0, $this->storage->variantsExist(self::BUCKET, $upload->getPathPrefix()));
        self::assertSame(0, $this->storage->heldVariantsExist($upload->getPathPrefix()), 'removed means removed everywhere');
    }
}
