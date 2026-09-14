<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Media;

use App\Catalog\Entity\Item;
use App\Catalog\Entity\Region;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Entity\User;
use App\Media\Entity\ConsentRecord;
use App\Media\Entity\MediaUpload;
use App\Media\MediaConsent;
use App\Media\MediaDecisionService;
use App\Moderation\Entity\ModeratorArea;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The curator's "Taken here" button and the list it sits in
 * (docs/specs/photo-uploads.md §5g, docs/specs/scenic-views.md §8).
 *
 * `GET /map/item/{id}/hidden-photos` names the rider photos a scenic view
 * hides and why; `POST /moderate/photo/{uuid}/taken-here` confirms one.
 */
final class PhotoTakenHereEndpointTest extends WebTestCase
{
    private KernelBrowser $client;
    private int $seq = 0;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @param list<string> $roles */
    private function user(string $email, array $roles = []): User
    {
        $user = (new User())->setEmail($email);
        $user->setPassword('x');
        $user->setDisplayName('Taken Here '.$this->seq);
        $user->setPublicProfile(true);
        $user->setRoles($roles);
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        if ([] !== $roles) {
            // Elevated roles must be TOTP-enrolled or every page redirects to /2fa/setup.
            $user->setTotpSecret('JBSWY3DPEHPK3PXP');
            $user->setTwoFaEnabled(true);
        }
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function item(string $letter, ?int $regionId = null): Item
    {
        $item = (new Item())->setLetter($letter)->setName('Taken here view '.++$this->seq)
            ->setGeom('{"type":"Point","coordinates":[4.9,52.4]}')->setCountryCode('NL')
            ->setSourceRef('taken-here-test-'.$this->seq)
            ->setSource(ItemSource::User)->setState(ItemState::Verified)
            ->setRegionId($regionId);
        $this->em()->persist($item);
        $this->em()->flush();

        return $item;
    }

    /** An approved rider photo in the item's gallery. */
    private function photo(Item $item, ?int $distanceM, ?\DateTimeImmutable $takenAt = null): MediaUpload
    {
        $owner = $this->user('taken-here-owner-'.++$this->seq.'@example.com');
        $consent = new ConsentRecord(Uuid::v4(), (int) $owner->getId(), MediaConsent::KIND, MediaConsent::VERSION, MediaConsent::hash('x'));
        $this->em()->persist($consent);
        $upload = new MediaUpload(Uuid::v4(), (int) $owner->getId(), $consent->getId(), 'EU', 1200, 900, 4242, $takenAt, bucket: 'test-bucket-eu-01');
        // Measured to the pin the item has now.
        $upload->resolveGps($distanceM, 52.4, 4.9);
        $this->em()->persist($upload);
        $upload->approve($item->getId());
        $this->em()->flush();

        $attributes = $item->getAttributes();
        $attributes['photos'] = [...($attributes['photos'] ?? []), static::getContainer()->get(MediaDecisionService::class)->describe($upload)];
        $item->setAttributes($attributes);
        $this->em()->flush();

        return $upload;
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }

    private function token(): string
    {
        $this->client->request('GET', '/map');
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertSame(1, preg_match('~window\.CC_PHOTO_TAKEN_HERE_TOKEN = "([^"]+)"~', $html, $m), 'the curator map page carries the token');

        return $m[1];
    }

    private function confirm(MediaUpload $upload, string $token): void
    {
        $this->client->request('POST', '/moderate/photo/'.$upload->getId()->toRfc4122().'/taken-here', ['_token' => $token], [], ['HTTP_ACCEPT' => 'application/json']);
    }

    public function testACuratorSeesTheHiddenRiderPhotosAndWhy(): void
    {
        $item = $this->item('P');
        $near = $this->photo($item, 40);
        $none = $this->photo($item, null, new \DateTimeImmutable('2026-05-03'));
        $far = $this->photo($item, 540);
        $this->client->loginUser($this->user('taken-here-curator@example.com', ['ROLE_CURATOR']));

        $this->client->request('GET', '/map/item/'.$item->getId().'/hidden-photos');

        self::assertResponseIsSuccessful();
        $cache = (string) $this->client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cache);
        self::assertStringContainsString('no-store', $cache);
        $photos = $this->json()['photos'];
        self::assertIsArray($photos);
        self::assertSame([$none->getId()->toRfc4122(), $far->getId()->toRfc4122()], array_column($photos, 'id'), 'the photo taken 40 m away already shows');
        self::assertNotContains($near->getId()->toRfc4122(), array_column($photos, 'id'));
        self::assertSame('no_gps', $photos[0]['reason']);
        self::assertSame('2026-05', $photos[0]['takenAt']);
        self::assertArrayNotHasKey('distanceM', $photos[0]);
        self::assertSame('too_far', $photos[1]['reason']);
        self::assertSame(540, $photos[1]['distanceM']);
        foreach (['sm', 'credit'] as $key) {
            self::assertArrayHasKey($key, $photos[0]);
        }
    }

    /** The curator is told a photo is hidden because the pin moved, and how far away it may now be. */
    public function testAPhotoTheMovedPinHidesSaysThePinMoved(): void
    {
        $item = $this->item('P');
        $measured = $this->photo($item, 100);
        $confirmed = $this->photo($item, null);
        $curator = $this->user('taken-here-moved@example.com', ['ROLE_CURATOR']);
        $this->client->loginUser($curator);
        $this->confirm($confirmed, $this->token());
        self::assertResponseIsSuccessful();

        $moved = $this->em()->find(Item::class, $item->getId());
        self::assertNotNull($moved);
        // About 300 m north of where both photos were measured and confirmed.
        $moved->setGeom('{"type":"Point","coordinates":[4.9,52.4027]}');
        $this->em()->flush();

        $this->client->request('GET', '/map/item/'.$item->getId().'/hidden-photos');

        $photos = $this->json()['photos'];
        self::assertIsArray($photos);
        self::assertSame([$measured->getId()->toRfc4122(), $confirmed->getId()->toRfc4122()], array_column($photos, 'id'));
        self::assertSame('pin_moved', $photos[0]['reason']);
        self::assertSame(401, $photos[0]['distanceM'], 'taken up to 100 m + 300 m from the pin');
        self::assertSame('pin_moved', $photos[1]['reason'], 'confirmed at the pin as it stood');
        self::assertSame(301, $photos[1]['distanceM'], 'confirmed 0 m from the old pin, which is 300 m away');
    }

    public function testAnotherLetterHasNoHiddenPhotos(): void
    {
        $item = $this->item('Q');
        $this->photo($item, null);
        $this->client->loginUser($this->user('taken-here-curator-q@example.com', ['ROLE_CURATOR']));

        $this->client->request('GET', '/map/item/'.$item->getId().'/hidden-photos');

        self::assertResponseIsSuccessful();
        self::assertSame(['photos' => []], $this->json());
    }

    public function testARiderMayNotListHiddenPhotos(): void
    {
        $item = $this->item('P');
        $this->photo($item, null);
        $this->client->loginUser($this->user('taken-here-rider-list@example.com'));

        $this->client->request('GET', '/map/item/'.$item->getId().'/hidden-photos');

        self::assertResponseStatusCodeSame(403);
    }

    public function testACuratorConfirmsAndThePhotoIsNoLongerHidden(): void
    {
        $item = $this->item('P');
        $upload = $this->photo($item, null);
        $curator = $this->user('taken-here-confirm@example.com', ['ROLE_CURATOR']);
        $this->client->loginUser($curator);

        $this->confirm($upload, $this->token());

        self::assertResponseIsSuccessful();
        self::assertSame(['ok' => true], $this->json());
        $this->em()->clear();
        $row = $this->em()->find(MediaUpload::class, $upload->getId());
        self::assertSame($curator->getId(), $row?->getLocationConfirmedBy());
        $photos = $this->em()->find(Item::class, $item->getId())?->getAttributes()['photos'] ?? [];
        self::assertTrue($photos[0]['locationConfirmed'] ?? null);

        $this->client->request('GET', '/map/item/'.$item->getId().'/hidden-photos');
        self::assertSame(['photos' => []], $this->json());
    }

    public function testTheTokenIsEnforced(): void
    {
        $upload = $this->photo($this->item('P'), null);
        $this->client->loginUser($this->user('taken-here-csrf@example.com', ['ROLE_CURATOR']));

        $this->confirm($upload, 'not-the-token');

        self::assertResponseStatusCodeSame(403);
        $this->em()->clear();
        self::assertFalse($this->em()->find(MediaUpload::class, $upload->getId())?->isLocationConfirmed());
    }

    public function testARiderMayNotConfirm(): void
    {
        $upload = $this->photo($this->item('P'), null);
        $this->client->loginUser($this->user('taken-here-rider@example.com'));

        $this->confirm($upload, 'irrelevant');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAPhotoOnAnotherLetterIsNotFound(): void
    {
        $upload = $this->photo($this->item('Q'), null);
        $this->client->loginUser($this->user('taken-here-other@example.com', ['ROLE_CURATOR']));

        $this->confirm($upload, $this->token());

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnUnknownPhotoIsNotFound(): void
    {
        $this->client->loginUser($this->user('taken-here-unknown@example.com', ['ROLE_CURATOR']));
        $token = $this->token();

        $this->client->request('POST', '/moderate/photo/'.Uuid::v4()->toRfc4122().'/taken-here', ['_token' => $token]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testACuratorOutsideTheItemsAreaMayNotConfirm(): void
    {
        $region = (new Region())->setSlug('taken-here-far-'.bin2hex(random_bytes(3)))->setName('Far away')->setCountryCode('NL')
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[4.0,52.0],[5.0,52.0],[5.0,53.0],[4.0,53.0],[4.0,52.0]]]]}');
        $this->em()->persist($region);
        $other = (new Region())->setSlug('taken-here-home-'.bin2hex(random_bytes(3)))->setName('Home')->setCountryCode('BE')
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[4.0,50.0],[5.0,50.0],[5.0,51.0],[4.0,51.0],[4.0,50.0]]]]}');
        $this->em()->persist($other);
        $this->em()->flush();

        $item = $this->item('P', $region->getId());
        $upload = $this->photo($item, null);
        $curator = $this->user('taken-here-scoped@example.com', ['ROLE_CURATOR']);
        $this->em()->persist(new ModeratorArea((int) $curator->getId(), (int) $other->getId(), null));
        $this->em()->flush();
        $this->client->loginUser($curator);

        $this->client->request('GET', '/map/item/'.$item->getId().'/hidden-photos');
        self::assertSame(['photos' => []], $this->json(), 'nothing to act on outside the area');

        $this->confirm($upload, $this->token());
        self::assertResponseStatusCodeSame(403);
    }
}
