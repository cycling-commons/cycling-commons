<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Media;

use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\Entity\RouteChangeHistory;
use App\Catalog\Entity\RouteSuggestion;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\RouteSuggestionReason;
use App\Catalog\RouteSuggestionStatus;
use App\Contribution\RoutePhotoService;
use App\Entity\User;
use App\Media\Entity\ConsentRecord;
use App\Media\Entity\MediaUpload;
use App\Media\MediaClaimService;
use App\Media\MediaConsent;
use App\Media\MediaDisposalService;
use App\Media\MediaStatus;
use App\Media\MediaStorage;
use App\Media\MediaTakedownService;
use App\Media\ProcessedPhoto;
use App\Moderation\ModerationScope;
use App\Moderation\RouteModerationService;
use App\Moderation\RouteQueue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Rider photos on a recommended route (docs/specs/photo-uploads.md §5i,
 * route-domain.md §4.5): claimed by the proposal or by a photo correction,
 * decided by the route's own approve/reject or done/dismiss, landing in the
 * route's `attributes.photos`, and withdrawn, anonymised and collected like a
 * photo on a place.
 */
final class RoutePhotoTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private MediaClaimService $claims;
    private RouteModerationService $moderation;
    private User $rider;
    private User $curator;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->claims = static::getContainer()->get(MediaClaimService::class);
        $this->moderation = static::getContainer()->get(RouteModerationService::class);

        $this->rider = (new User())->setEmail('route-photo-rider-'.bin2hex(random_bytes(4)).'@test.test');
        $this->rider->setPassword('x');
        $this->rider->setDisplayName('Lieve Janssens');
        $this->rider->setPublicProfile(true);
        $this->em->persist($this->rider);

        $this->curator = (new User())->setEmail('route-photo-curator-'.bin2hex(random_bytes(4)).'@test.test');
        $this->curator->setPassword('x');
        $this->curator->setRoles(['ROLE_CURATOR'])->setTotpSecret('JBSWY3DPEHPK3PXP');
        $this->curator->setTwoFaEnabled(true);
        $this->em->persist($this->curator);
        $this->em->flush();
    }

    /** @param array<string, mixed> $attributes */
    private function route(ItemState $state, array $attributes = []): RecommendedRoute
    {
        $route = (new RecommendedRoute())->setName('Rondje foto '.bin2hex(random_bytes(3)))
            // A straight line due north along 5.30 E, 50.40 to 50.50 N.
            ->setGeom('{"type":"LineString","coordinates":[[5.3,50.4],[5.3,50.5]]}')
            ->setState($state)->setSource(ItemSource::User)
            ->setSourceRef('user:'.bin2hex(random_bytes(8)))->setRegionId(null)
            ->setProposedBy((int) $this->rider->getId())
            ->setAttributes($attributes);
        $this->em->persist($route);
        $this->em->flush();

        return $route;
    }

    /** A released upload of the rider's, not yet claimed; GPS as the worker remembered it. */
    private function upload(?float $gpsLat = null, ?float $gpsLng = null, ?User $owner = null): MediaUpload
    {
        $owner ??= $this->rider;
        $consent = new ConsentRecord(Uuid::v4(), (int) $owner->getId(), MediaConsent::KIND, MediaConsent::VERSION, MediaConsent::hash('x'));
        $this->em->persist($consent);
        $upload = new MediaUpload(Uuid::v4(), (int) $owner->getId(), $consent->getId(), 'EU', 1200, 900, 4242, new \DateTimeImmutable('2026-05-03'), bucket: 'test-bucket-eu-01');
        $upload->rememberGps($gpsLat, $gpsLng);
        $this->em->persist($upload);
        $this->em->flush();
        static::getContainer()->get(MediaStorage::class)->store('test-bucket-eu-01', $upload->getPathPrefix(), new ProcessedPhoto('O', 'L', 'S', 1200, 900));

        return $upload;
    }

    private function reload(MediaUpload $upload): MediaUpload
    {
        $row = $this->em->find(MediaUpload::class, $upload->getId());
        self::assertInstanceOf(MediaUpload::class, $row);

        return $row;
    }

    public function testAPhotoSentWithAProposalIsMeasuredToTheNearestPointOfTheLine(): void
    {
        $route = $this->route(ItemState::Submitted);
        // About 700 m east of the line at 50.45 N.
        $upload = $this->upload(50.45, 5.31);

        $this->claims->claimForRoute(json_encode([$upload->getId()->toRfc4122()]), $this->rider, $route, null);
        $this->em->flush();
        $this->em->clear();

        $row = $this->reload($upload);
        self::assertSame($route->getId(), $row->getRouteId());
        self::assertNull($row->getRouteSuggestionId());
        self::assertNull($row->getSubmissionId());
        self::assertEqualsWithDelta(711, (int) $row->getGpsDistanceM(), 15, 'the distance is to the line, not to one end of it');
        $pin = $row->getGpsDistancePin();
        self::assertNotNull($pin);
        self::assertEqualsWithDelta(50.45, $pin[0], 0.001);
        self::assertEqualsWithDelta(5.3, $pin[1], 0.0001);
        self::assertNull($row->getGpsLat(), 'the coordinates are destroyed at the claim');
    }

    public function testAClaimedUploadCannotBeClaimedAgainNorByAnotherRider(): void
    {
        $route = $this->route(ItemState::Submitted);
        $upload = $this->upload();
        $this->claims->claimForRoute([$upload->getId()->toRfc4122()], $this->rider, $route, null);
        $this->em->flush();

        try {
            $this->claims->claimForRoute([$upload->getId()->toRfc4122()], $this->rider, $route, null);
            self::fail('a claimed upload was claimed twice');
        } catch (\InvalidArgumentException) {
        }

        $strangers = $this->upload(owner: $this->curator);
        $this->expectException(\InvalidArgumentException::class);
        $this->claims->claimForRoute([$strangers->getId()->toRfc4122()], $this->rider, $route, null);
    }

    public function testApprovingTheProposalPutsItsPhotosOnTheRouteAndKeepsTheUntickedOnesOff(): void
    {
        $route = $this->route(ItemState::Submitted);
        $kept = $this->upload();
        $dropped = $this->upload();
        $this->claims->claimForRoute([$kept->getId()->toRfc4122(), $dropped->getId()->toRfc4122()], $this->rider, $route, null);
        $this->em->flush();

        $this->moderation->approve((int) $route->getId(), $this->curator, [$dropped->getId()->toRfc4122()]);
        $this->em->clear();

        $reloaded = $this->em->find(RecommendedRoute::class, $route->getId());
        self::assertNotNull($reloaded);
        $photos = $reloaded->getAttributes()['photos'] ?? null;
        self::assertIsArray($photos);
        self::assertCount(1, $photos);
        self::assertSame($kept->getId()->toRfc4122(), $photos[0]['id']);
        self::assertSame('Lieve Janssens', $photos[0]['credit']);
        self::assertSame('CC BY-SA 4.0', $photos[0]['license']);
        self::assertSame('2026-05', $photos[0]['takenAt']);

        self::assertSame(MediaStatus::Approved, $this->reload($kept)->getStatus());
        self::assertSame($route->getId(), $this->reload($kept)->getRouteId());
        self::assertNull($this->reload($kept)->getItemId());
        self::assertSame(MediaStatus::Rejected, $this->reload($dropped)->getStatus());

        $history = $this->em->getRepository(RouteChangeHistory::class)->findOneBy(['routeId' => $route->getId(), 'field' => 'photos']);
        self::assertNotNull($history, 'the gallery change is in the route history');
    }

    public function testApprovalMovesALegacySinglePhotoIntoTheGallery(): void
    {
        $commons = ['sm' => 'https://img.test/eu/a/sm.webp', 'lg' => 'https://img.test/eu/a/lg.webp', 'credit' => 'X', 'license' => 'CC BY-SA 4.0'];
        $route = $this->route(ItemState::Submitted, ['photo' => $commons]);
        $upload = $this->upload();
        $this->claims->claimForRoute([$upload->getId()->toRfc4122()], $this->rider, $route, null);
        $this->em->flush();

        $this->moderation->approve((int) $route->getId(), $this->curator);
        $this->em->clear();

        $attributes = $this->em->find(RecommendedRoute::class, $route->getId())?->getAttributes() ?? [];
        self::assertArrayNotHasKey('photo', $attributes);
        self::assertCount(2, $attributes['photos']);
        self::assertSame($commons['sm'], $attributes['photos'][0]['sm']);
    }

    public function testRejectingTheProposalRejectsItsPhotos(): void
    {
        $route = $this->route(ItemState::Submitted);
        $upload = $this->upload();
        $this->claims->claimForRoute([$upload->getId()->toRfc4122()], $this->rider, $route, null);
        $this->em->flush();

        $this->moderation->reject((int) $route->getId(), $this->curator, 'Not a loop');
        $this->em->clear();

        self::assertSame(MediaStatus::Rejected, $this->reload($upload)->getStatus());
        self::assertArrayNotHasKey('photos', $this->em->find(RecommendedRoute::class, $route->getId())?->getAttributes() ?? []);
    }

    public function testARidersPhotosForALiveRouteWaitAsAPhotoCorrectionUntilDone(): void
    {
        $route = $this->route(ItemState::Unverified);
        $upload = $this->upload();

        $result = static::getContainer()->get(RoutePhotoService::class)
            ->submit($route, $this->rider, json_encode([$upload->getId()->toRfc4122()]), null, 'From the bridge');
        self::assertFalse($result['applied']);
        $suggestionId = (int) $result['suggestion']->getId();
        $this->em->clear();

        $suggestion = $this->em->find(RouteSuggestion::class, $suggestionId);
        self::assertNotNull($suggestion);
        self::assertSame(RouteSuggestionReason::Photo, $suggestion->getReason());
        self::assertSame(RouteSuggestionStatus::Pending, $suggestion->getStatus());
        self::assertSame($suggestionId, $this->reload($upload)->getRouteSuggestionId());
        self::assertSame(MediaStatus::Pending, $this->reload($upload)->getStatus());

        $desk = static::getContainer()->get(RouteQueue::class)->pendingSuggestions(ModerationScope::global(), null);
        $row = array_values(array_filter($desk, static fn (array $s): bool => $s['id'] === $suggestionId))[0] ?? null;
        self::assertNotNull($row);
        self::assertCount(1, $row['photos'], 'the desk shows the photo on the correction');

        $this->moderation->resolveSuggestion($suggestionId, RouteSuggestionStatus::Done, $this->curator);
        $this->em->clear();

        $photos = $this->em->find(RecommendedRoute::class, $route->getId())?->getAttributes()['photos'] ?? [];
        self::assertCount(1, $photos);
        self::assertSame(MediaStatus::Approved, $this->reload($upload)->getStatus());
    }

    public function testDismissingAPhotoCorrectionRejectsItsPhotos(): void
    {
        $route = $this->route(ItemState::Verified);
        $upload = $this->upload();
        $result = static::getContainer()->get(RoutePhotoService::class)
            ->submit($route, $this->rider, [$upload->getId()->toRfc4122()], null, null);

        $this->moderation->resolveSuggestion((int) $result['suggestion']->getId(), RouteSuggestionStatus::Dismissed, $this->curator);
        $this->em->clear();

        self::assertSame(MediaStatus::Rejected, $this->reload($upload)->getStatus());
        self::assertArrayNotHasKey('photos', $this->em->find(RecommendedRoute::class, $route->getId())?->getAttributes() ?? []);
    }

    public function testACuratorsOwnPhotosApplyAtOnce(): void
    {
        $route = $this->route(ItemState::Unverified);
        $upload = $this->upload(owner: $this->curator);

        $result = static::getContainer()->get(RoutePhotoService::class)
            ->submit($route, $this->curator, [$upload->getId()->toRfc4122()], null, null);
        $this->em->clear();

        self::assertTrue($result['applied']);
        self::assertSame(RouteSuggestionStatus::Done, $this->em->find(RouteSuggestion::class, $result['suggestion']->getId())?->getStatus());
        self::assertCount(1, $this->em->find(RecommendedRoute::class, $route->getId())?->getAttributes()['photos'] ?? []);
    }

    public function testPhotosForARouteThatIsNotLiveOrWithoutPhotosAreRefused(): void
    {
        $service = static::getContainer()->get(RoutePhotoService::class);
        try {
            $service->submit($this->route(ItemState::Submitted), $this->rider, [$this->upload()->getId()->toRfc4122()], null, null);
            self::fail('photos went onto a route waiting for review');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('propose_route.photos.error.route_gone', $e->getMessage());
        }

        $this->expectExceptionMessage('propose_route.photos.error.none');
        $service->submit($this->route(ItemState::Unverified), $this->rider, '', null, null);
    }

    public function testARouteClaimedUploadIsNeverAnOrphan(): void
    {
        $route = $this->route(ItemState::Submitted);
        $upload = $this->upload();
        $this->claims->claimForRoute([$upload->getId()->toRfc4122()], $this->rider, $route, null);
        $this->em->flush();
        $this->em->getConnection()->executeStatement("UPDATE media_upload SET created_at = NOW() - INTERVAL '30 days' WHERE id = :id", ['id' => $upload->getId()->toRfc4122()]);

        static::getContainer()->get(MediaDisposalService::class)->collectOrphans();
        $this->em->clear();

        self::assertNotNull($this->em->find(MediaUpload::class, $upload->getId()), 'a photo waiting for a curator is not garbage');
    }

    public function testTrashingAProposalTakesItsPhotosWithIt(): void
    {
        $route = $this->route(ItemState::Submitted);
        $upload = $this->upload();
        $this->claims->claimForRoute([$upload->getId()->toRfc4122()], $this->rider, $route, null);
        $this->em->flush();

        $this->moderation->trashProposal((int) $route->getId(), $this->curator);
        $this->em->clear();

        self::assertNull($this->em->find(MediaUpload::class, $upload->getId()));
    }

    public function testATakedownTakesAnApprovedPhotoOffTheRoute(): void
    {
        $route = $this->route(ItemState::Submitted);
        $upload = $this->upload();
        $this->claims->claimForRoute([$upload->getId()->toRfc4122()], $this->rider, $route, null);
        $this->em->flush();
        $this->moderation->approve((int) $route->getId(), $this->curator);
        $this->em->clear();

        static::getContainer()->get(MediaTakedownService::class)->request($this->reload($upload), 'It shows my house.');
        $this->em->clear();

        self::assertArrayNotHasKey('photos', $this->em->find(RecommendedRoute::class, $route->getId())?->getAttributes() ?? []);
    }
}
