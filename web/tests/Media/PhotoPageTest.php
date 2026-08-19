<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Media;

use App\Entity\User;
use App\Media\Entity\ConsentRecord;
use App\Media\Entity\MediaUpload;
use App\Media\MediaConsent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The target of every stored file's embedded attribution link
 * (docs/specs/photo-uploads.md §5d). Its whole purpose is that the answer it
 * gives is CURRENT: a rider who goes private, or leaves, changes what every
 * copy of their photo attributes to — including copies downloaded years
 * earlier.
 */
final class PhotoPageTest extends WebTestCase
{
    private function rider(EntityManagerInterface $em, string $email, bool $public): User
    {
        $user = (new User())->setEmail($email);
        $user->setPassword('x');
        $user->setDisplayName('Marta Verhoeven');
        $user->setPublicProfile($public);
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function upload(EntityManagerInterface $em, ?User $owner): MediaUpload
    {
        $ownerId = null !== $owner ? (int) $owner->getId() : 1;
        $consent = new ConsentRecord(Uuid::v4(), $ownerId, MediaConsent::KIND, MediaConsent::VERSION, MediaConsent::hash('x'));
        $em->persist($consent);

        $upload = new MediaUpload(Uuid::v4(), $ownerId, $consent->getId(), 'EU', 1200, 900, 4242, shard: 'EU-01');
        $em->persist($upload);
        $em->flush();

        return $upload;
    }

    public function testAnApprovedPhotoStatesItsLicenceAndItsPhotographer(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $rider = $this->rider($em, 'page-public@example.test', public: true);
        $upload = $this->upload($em, $rider);
        $upload->claim(1);
        $upload->approve(null);
        $em->flush();

        $crawler = $client->request('GET', '/photo/'.$upload->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('CC BY-SA 4.0', $crawler->text());
        self::assertStringContainsString('Marta Verhoeven', $crawler->text());
        self::assertGreaterThan(0, $crawler->filter('a[href*="/riders/"]')->count(), 'a public profile is linked');
        self::assertGreaterThan(0, $crawler->filter('a[href$="/orig.webp"]')->count(), 'the reuse asset is offered');
    }

    public function testAPrivateProfileIsCreditedAnonymously(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $rider = $this->rider($em, 'page-private@example.test', public: false);
        $upload = $this->upload($em, $rider);
        $upload->claim(1);
        $upload->approve(null);
        $em->flush();

        $crawler = $client->request('GET', '/photo/'.$upload->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Marta Verhoeven', $crawler->text());
        self::assertSame(0, $crawler->filter('a[href*="/riders/"]')->count());
    }

    public function testGoingPrivateChangesWhatEveryDownloadedCopyAttributesTo(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $rider = $this->rider($em, 'page-flip@example.test', public: true);
        $upload = $this->upload($em, $rider);
        $upload->claim(1);
        $upload->approve(null);
        $em->flush();
        $url = '/photo/'.$upload->getId()->toRfc4122();

        self::assertStringContainsString('Marta Verhoeven', $client->request('GET', $url)->text());

        $rider->setPublicProfile(false);
        $em->flush();

        self::assertStringNotContainsString(
            'Marta Verhoeven',
            $client->request('GET', $url)->text(),
            'the same URL, the same file in the wild, a different answer — that is the point',
        );
    }

    public function testADeletedRiderWhoKeptTheirCreditIsStillNamed(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $upload = $this->upload($em, null);
        $upload->claim(1);
        $upload->approve(null);
        $upload->anonymize('Marta Verhoeven');
        $em->flush();

        $crawler = $client->request('GET', '/photo/'.$upload->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Marta Verhoeven', $crawler->text());
        self::assertSame(0, $crawler->filter('a[href*="/riders/"]')->count(), 'there is no profile left to link to');
    }

    public function testAPendingPhotoIsNotPublished(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $rider = $this->rider($em, 'page-pending@example.test', public: true);
        $upload = $this->upload($em, $rider);

        $client->request('GET', '/photo/'.$upload->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(404, 'the queue stays the only place a pending photo is linked from');
    }

    public function testARejectedPhotoIsNotPublished(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $rider = $this->rider($em, 'page-rejected@example.test', public: true);
        $upload = $this->upload($em, $rider);
        $upload->claim(1);
        $upload->reject();
        $em->flush();

        $client->request('GET', '/photo/'.$upload->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(404);
    }

    public function testATombstonedPhotoIsNotPublishedEither(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $rider = $this->rider($em, 'page-tombstone@example.test', public: true);
        $upload = $this->upload($em, $rider);
        $upload->claim(1);
        $upload->approve(null);
        $upload->markObjectsDeleted();
        $em->flush();

        $client->request('GET', '/photo/'.$upload->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(404, 'the bytes are gone; the page must not pretend otherwise');
    }

    public function testAnUnknownUuidIsNotPublished(): void
    {
        $client = static::createClient();

        $client->request('GET', '/photo/'.Uuid::v4()->toRfc4122());

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnonymousVisitorsAreNeverRedirectedToLogin(): void
    {
        $client = static::createClient();

        $client->request('GET', '/photo/'.Uuid::v4()->toRfc4122());

        self::assertResponseStatusCodeSame(404, 'a reuser following a link from a file is not asked to sign in');
    }
}
