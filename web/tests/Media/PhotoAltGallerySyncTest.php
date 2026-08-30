<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * A photo's description lives in two places, and an edit has to write both.
 *
 * On the upload row, and again inside the item's `photos` gallery, which is
 * what the map, the vector tiles and the contribute wizard's review step all
 * read. Writing only the row left every one of those surfaces showing the
 * description as it stood at approval, which is what made an edit look as
 * though it had not happened (owner, 2026-08-30: "again the text for the
 * already existing image is not visible").
 *
 * The gallery copy is not a cache that can be rebuilt on read: it rides inside
 * cached tiles, so it is written at the same moment as the row.
 *
 * @see docs/specs/photo-uploads.md §5e
 */
final class PhotoAltGallerySyncTest extends WebTestCase
{
    /**
     * The same token the upload script uses.
     *
     * `/media/token` issues it inside a real request, which is what the CSRF
     * storage needs: reaching for the manager directly has no session to put
     * one in.
     */
    private function tokenFor(KernelBrowser $client): string
    {
        $client->request('GET', '/media/token');
        self::assertResponseIsSuccessful();

        /** @var array{token?: string} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return (string) ($data['token'] ?? '');
    }

    public function testEditingADescriptionUpdatesTheGalleryToo(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = (new User())->setEmail('alt-gallery@example.test');
        $owner->setPassword('x');
        $owner->setDisplayName('Alt Gallery Rider');
        $em->persist($owner);

        $item = (new Item())->setLetter('A')->setName('Fontaine du regard')
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setSourceRef('alt-gallery-test')
            ->setSource(ItemSource::User)->setState(ItemState::Verified);
        $em->persist($item);
        $em->flush();

        $consent = new ConsentRecord(Uuid::v4(), (int) $owner->getId(), MediaConsent::KIND, MediaConsent::VERSION, MediaConsent::hash('x'));
        $em->persist($consent);
        $upload = new MediaUpload(Uuid::v4(), (int) $owner->getId(), $consent->getId(), 'EU', 1200, 900, 4242, bucket: 'test-bucket-eu-01');
        $em->persist($upload);
        $upload->approve($item->getId());
        $upload->setAltText('The old words.');
        $em->flush();

        $entry = static::getContainer()->get(MediaDecisionService::class)->describe($upload);
        $item->setAttributes(['photos' => [$entry]]);
        $em->flush();
        self::assertSame('The old words.', $item->getAttributes()['photos'][0]['alt'] ?? null);

        $client->loginUser($owner);
        $token = $this->tokenFor($client);
        $client->request('POST', '/media/photos/'.$upload->getId()->toRfc4122().'/alt', [
            '_token' => $token,
            'alt' => 'A stone basin under a plane tree, water running.',
        ]);

        self::assertResponseIsSuccessful();
        $em->clear();

        $freshUpload = $em->find(MediaUpload::class, $upload->getId());
        $freshItem = $em->find(Item::class, $item->getId());
        self::assertInstanceOf(MediaUpload::class, $freshUpload);
        self::assertInstanceOf(Item::class, $freshItem);
        self::assertSame('A stone basin under a plane tree, water running.', $freshUpload->getAltText());
        self::assertSame(
            'A stone basin under a plane tree, water running.',
            $freshItem->getAttributes()['photos'][0]['alt'] ?? null,
            'the gallery every surface reads follows the edit',
        );
    }

    /**
     * Emptying it removes the key rather than storing an empty string.
     *
     * An absent key is what lets the render side fall back to the place's name,
     * which is better than an empty alt and much better than a filename.
     */
    public function testEmptyingADescriptionClearsTheGalleryKey(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = (new User())->setEmail('alt-gallery-clear@example.test');
        $owner->setPassword('x');
        $owner->setDisplayName('Alt Clear Rider');
        $em->persist($owner);

        $item = (new Item())->setLetter('A')->setName('Fontaine du regard')
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setSourceRef('alt-gallery-clear-test')
            ->setSource(ItemSource::User)->setState(ItemState::Verified);
        $em->persist($item);
        $em->flush();

        $consent = new ConsentRecord(Uuid::v4(), (int) $owner->getId(), MediaConsent::KIND, MediaConsent::VERSION, MediaConsent::hash('x'));
        $em->persist($consent);
        $upload = new MediaUpload(Uuid::v4(), (int) $owner->getId(), $consent->getId(), 'EU', 1200, 900, 4242, bucket: 'test-bucket-eu-01');
        $em->persist($upload);
        $upload->approve($item->getId());
        $upload->setAltText('Something that will go.');
        $em->flush();

        $entry = static::getContainer()->get(MediaDecisionService::class)->describe($upload);
        $item->setAttributes(['photos' => [$entry]]);
        $em->flush();

        $client->loginUser($owner);
        $token = $this->tokenFor($client);
        $client->request('POST', '/media/photos/'.$upload->getId()->toRfc4122().'/alt', ['_token' => $token, 'alt' => '']);

        self::assertResponseIsSuccessful();
        $em->clear();

        $freshItem = $em->find(Item::class, $item->getId());
        self::assertInstanceOf(Item::class, $freshItem);
        self::assertArrayNotHasKey('alt', $freshItem->getAttributes()['photos'][0]);
    }
}
