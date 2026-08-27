<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Tests\Media;

use App\Catalog\Entity\Item;
use App\Entity\User;
use App\Media\Entity\ConsentRecord;
use App\Media\Entity\MediaUpload;
use App\Media\MediaConsent;
use App\Media\RiderCreditSync;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Turning a public profile off has to take the name off the map, not just off
 * the photo page.
 *
 * Reported 2026-08-28 with a screenshot: the settings page said "your profile
 * is hidden and contributions stay anonymous" while the map drawer still read
 * "© XanderK". The photo page was right, because it resolves the credit per
 * render; the map was wrong, because the credit is a **stored** string written
 * once when a curator approved the upload. The store is deliberate, the gallery
 * rides along in cached public JSON, but it meant the string never caught up.
 *
 * @see docs/specs/photo-uploads.md §5d
 */
final class RiderCreditSyncTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private RiderCreditSync $sync;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->sync = self::getContainer()->get(RiderCreditSync::class);
    }

    public function testTurningTheProfileOffClearsTheStoredCredit(): void
    {
        [$user, $item, $upload] = $this->publishedPhotoBy('Namedrider', publicProfile: true);

        self::assertSame('Namedrider', $this->creditOn($item), 'a public profile is credited by name');

        $user->setPublicProfile(false);
        self::assertSame(1, $this->sync->resync($user));

        self::assertSame('', $this->creditOn($item), 'the name has to leave the gallery, not only the photo page');
    }

    public function testTurningItBackOnRestoresTheName(): void
    {
        [$user, $item] = $this->publishedPhotoBy('Backagain', publicProfile: false);

        self::assertSame('', $this->creditOn($item));

        $user->setPublicProfile(true);
        self::assertSame(1, $this->sync->resync($user));

        self::assertSame('Backagain', $this->creditOn($item));
    }

    /**
     * A rename is the same defect wearing different clothes: the old name sits
     * on the map until something rewrites it.
     */
    public function testARenameFollowsThroughToTheGallery(): void
    {
        [$user, $item] = $this->publishedPhotoBy('Oldname', publicProfile: true);

        $user->setDisplayName('Newname');
        self::assertSame(1, $this->sync->resync($user));

        self::assertSame('Newname', $this->creditOn($item));
    }

    public function testAResyncThatChangesNothingWritesNothing(): void
    {
        [$user] = $this->publishedPhotoBy('Steady', publicProfile: true);

        self::assertSame(0, $this->sync->resync($user), 'a no-op save must not churn the gallery');
    }

    /**
     * Another rider's photo on the same item must not be touched: the walk is
     * per upload, and `isEntryFor()` is what keeps it that way.
     */
    public function testSomebodyElsesCreditIsLeftAlone(): void
    {
        [$user, $item, $upload] = $this->publishedPhotoBy('Mine', publicProfile: true);

        $attributes = $item->getAttributes();
        $attributes['photos'][] = ['id' => '11111111-1111-4111-8111-111111111111', 'sm' => '/x-sm.webp', 'credit' => 'Somebody Else'];
        $item->setAttributes($attributes);

        $user->setPublicProfile(false);
        self::assertSame(1, $this->sync->resync($user));

        $photos = $item->getAttributes()['photos'];
        self::assertSame('', $photos[0]['credit']);
        self::assertSame('Somebody Else', $photos[1]['credit'], 'only the rider whose profile changed may be re-stamped');
    }

    private function creditOn(Item $item): string
    {
        return (string) ($item->getAttributes()['photos'][0]['credit'] ?? 'MISSING');
    }

    /**
     * @return array{0: User, 1: Item, 2: MediaUpload}
     */
    private function publishedPhotoBy(string $displayName, bool $publicProfile): array
    {
        $user = (new User())
            ->setEmail('credit-'.bin2hex(random_bytes(4)).'@example.test')
            ->setPassword('x')
            ->setDisplayName($displayName)
            ->setPublicProfile($publicProfile);
        $this->em->persist($user);

        $item = new Item();
        $item->setName('Credit sync fixture');
        $item->setLetter('Q');
        $item->setGeom('{"type":"Point","coordinates":[5.07,52.33]}');
        $item->setCountryCode('NL');
        $this->em->persist($item);
        $this->em->flush();

        $consent = new ConsentRecord(Uuid::v4(), (int) $user->getId(), MediaConsent::KIND, MediaConsent::VERSION, MediaConsent::hash('x'));
        $this->em->persist($consent);

        $upload = new MediaUpload(Uuid::v4(), (int) $user->getId(), $consent->getId(), 'EU', 1200, 900, 4242, bucket: 'test-bucket-eu-01');
        // The entity owns the transition; there are no setters for these two.
        $upload->approve($item->getId());
        $this->em->persist($upload);
        $this->em->flush();

        $item->setAttributes(['photos' => [[
            'id' => $upload->getId()->toRfc4122(),
            'sm' => '/fixture-sm.webp',
            'credit' => $publicProfile ? $displayName : '',
        ]]]);
        $this->em->flush();

        return [$user, $item, $upload];
    }
}
