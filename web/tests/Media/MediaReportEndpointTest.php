<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Media;

use App\Entity\User;
use App\Media\Entity\ConsentRecord;
use App\Media\Entity\MediaUpload;
use App\Media\MediaConsent;
use App\Media\MediaTakedownSource;
use App\Security\FormGuard;
use App\Support\Entity\ContentReport;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * What is left of the photo report endpoint after the merge (2026-08-30).
 *
 * The form that lived at `/photo/{uuid}/report` is gone: photos are reported at
 * `/report/photo/{uuid}` with everything else, because one open map drawer
 * holds the entry AND its pictures and a rider should not have to leave the
 * page to report the one rather than the other
 * (docs/specs/2026-08-30-one-report-route-design.md §2).
 *
 * The response properties this file used to hold down are the shared route's
 * now and are tested in `App\Tests\Support\ContentReportTest`: a signed-out
 * visitor can file, the GET is not an existence oracle, and every outcome short
 * of a validation error looks identical from outside.
 *
 * What is still media's own, and is tested here:
 *
 * * the old URL still answers, permanently, and keeps a POST a POST;
 * * the photo page links the shared route directly rather than through it;
 * * a report filed there raises the media takedown request, which is the
 *   operational state a curator's decision on the reports desk then acts on.
 */
final class MediaReportEndpointTest extends WebTestCase
{
    /** KernelBrowser rebuilds the container between requests otherwise. */
    private function client(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    private function rider(EntityManagerInterface $em, string $email): User
    {
        $user = (new User())->setEmail($email);
        $user->setPassword('x');
        $user->setDisplayName('Report Endpoint Rider');
        $user->setPublicProfile(true);
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function approved(EntityManagerInterface $em, User $owner): MediaUpload
    {
        $ownerId = (int) $owner->getId();
        $consent = new ConsentRecord(Uuid::v4(), $ownerId, MediaConsent::KIND, MediaConsent::VERSION, MediaConsent::hash('x'));
        $em->persist($consent);

        $upload = new MediaUpload(Uuid::v4(), $ownerId, $consent->getId(), 'EU', 1200, 900, 4242, bucket: 'test-bucket-eu-01');
        $em->persist($upload);
        $upload->claim(1);
        $upload->approve(null);
        $em->flush();

        return $upload;
    }

    public function testTheOldFormUrlMovedPermanently(): void
    {
        $client = $this->client();
        $uuid = Uuid::v4()->toRfc4122();

        $client->request('GET', '/photo/'.$uuid.'/report');
        self::assertResponseStatusCodeSame(301);
        self::assertResponseRedirects('/report/photo/'.$uuid);
    }

    /**
     * A POST is redirected too, and keeps its method.
     *
     * A form left open in a tab before the change should not lose what somebody
     * typed into a dead end, which is what 308 is for and 301 is not.
     */
    public function testAPostToTheOldUrlKeepsItsMethod(): void
    {
        $client = $this->client();
        $uuid = Uuid::v4()->toRfc4122();

        $client->request('POST', '/photo/'.$uuid.'/report', ['reason' => 'kept']);
        self::assertResponseStatusCodeSame(308);
        self::assertResponseRedirects('/report/photo/'.$uuid);
    }

    public function testThePhotoPageLinksTheSharedRouteForEveryone(): void
    {
        $client = $this->client();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $upload = $this->approved($em, $this->rider($em, 're-link@example.test'));
        $uuid = $upload->getId()->toRfc4122();

        $crawler = $client->request('GET', '/photo/'.$uuid);

        self::assertResponseIsSuccessful();
        self::assertSame(
            1,
            $crawler->filter('a[href="/report/photo/'.$uuid.'"]')->count(),
            'a signed-out stranger sees the report link, pointing at the shared route',
        );
    }

    /**
     * A photo report raises the takedown request, for an ordinary ground too.
     *
     * Not only the urgent one: the report row is the DSA record and carries the
     * mails, while the takedown request is the state a curator grants or
     * declines. Without it the reports desk would have a decision to record and
     * no picture to act on.
     */
    public function testAReportOnTheSharedRouteRaisesTheTakedownRequest(): void
    {
        $client = $this->client();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $upload = $this->approved($em, $this->rider($em, 're-raise@example.test'));
        $uuid = $upload->getId()->toRfc4122();
        $guard = static::getContainer()->get(FormGuard::class);

        $page = $client->request('GET', '/report/photo/'.$uuid);
        $client->request('POST', '/report/photo/'.$uuid, [
            '_token' => (string) $page->filter('input[name="_token"]')->attr('value'),
            FormGuard::STAMP => $guard->stamp(new \DateTimeImmutable('-30 seconds')),
            'ground' => 'advertising',
            'reason' => 'This is a shop sign and nothing else.',
            'contact' => 'seen-it@cyclingcommons.org',
        ]);

        self::assertResponseRedirects();

        $fresh = $em->find(MediaUpload::class, $upload->getId());
        self::assertInstanceOf(MediaUpload::class, $fresh);
        self::assertTrue($fresh->isTakedownPending(), 'the curator has something to grant or decline');
        self::assertSame(MediaTakedownSource::ThirdParty, $fresh->getTakedownSource());
        self::assertFalse($fresh->isTakedownWithheld(), 'an ordinary ground does not hide it');

        $reports = $em->getRepository(ContentReport::class)->findAll();
        self::assertCount(1, $reports, 'and the DSA record exists alongside it');
    }
}
