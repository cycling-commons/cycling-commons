<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Contribution;

use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\Entity\RouteSuggestion;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\RouteSuggestionReason;
use App\Catalog\RouteSuggestionStatus;
use App\Contribution\RouteProposalService;
use App\Entity\User;
use App\Media\Entity\ConsentRecord;
use App\Media\Entity\MediaUpload;
use App\Media\MediaClaimService;
use App\Media\MediaConsent;
use App\Media\MediaStatus;
use App\Media\MediaStorage;
use App\Media\ProcessedPhoto;
use App\Moderation\AlreadyDecidedException;
use App\Moderation\NotTheSubmitterException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Photos on /propose-route (docs/specs/route-domain.md §4.5, §4.6,
 * photo-uploads.md §5i): with a new proposal, on the proposer's edit while it
 * waits, and for a live route through `?route=<id>`; decided on the Routes
 * desk with per-photo Keep boxes.
 */
final class RoutePhotoFlowTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    private function rider(): User
    {
        $u = (new User())->setEmail('rpf-rider-'.bin2hex(random_bytes(4)).'@test.test');
        $u->setPassword('x');
        $this->em->persist($u);
        $this->em->flush();

        return $u;
    }

    private function curator(): User
    {
        $u = (new User())->setEmail('rpf-curator-'.bin2hex(random_bytes(4)).'@test.test')->setDisplayName('C');
        $u->setEmailVerified(true)->setEmailVerifiedAt(new \DateTimeImmutable());
        $u->setRoles(['ROLE_CURATOR'])->setTotpSecret('JBSWY3DPEHPK3PXP');
        $u->setTwoFaEnabled(true);
        $u->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($u, 'password1234'));
        $this->em->persist($u);
        $this->em->flush();

        return $u;
    }

    private function route(ItemState $state, User $by): RecommendedRoute
    {
        $r = (new RecommendedRoute())->setName('Photo loop '.bin2hex(random_bytes(3)))
            ->setGeom('{"type":"LineString","coordinates":[[5.2,50.4],[5.3,50.5]]}')
            ->setDistanceM(14000)->setState($state)->setSource(ItemSource::User)
            ->setSourceRef('user:'.bin2hex(random_bytes(8)))->setRegionId(1)->setProposedBy((int) $by->getId());
        $this->em->persist($r);
        $this->em->flush();

        return $r;
    }

    private function upload(User $owner): MediaUpload
    {
        $consent = new ConsentRecord(Uuid::v4(), (int) $owner->getId(), MediaConsent::KIND, MediaConsent::VERSION, MediaConsent::hash('x'));
        $this->em->persist($consent);
        $upload = new MediaUpload(Uuid::v4(), (int) $owner->getId(), $consent->getId(), 'EU', 1200, 900, 4242, bucket: 'test-bucket-eu-01');
        $this->em->persist($upload);
        $this->em->flush();
        static::getContainer()->get(MediaStorage::class)->store('test-bucket-eu-01', $upload->getPathPrefix(), new ProcessedPhoto('O', 'L', 'S', 1200, 900));

        return $upload;
    }

    private function uploadStatus(MediaUpload $upload): MediaStatus
    {
        $this->em->clear();
        $row = $this->em->find(MediaUpload::class, $upload->getId());
        self::assertInstanceOf(MediaUpload::class, $row);

        return $row->getStatus();
    }

    private static function gpx(): string
    {
        $pts = '';
        for ($i = 0; $i <= 100; ++$i) {
            $pts .= sprintf('<trkpt lat="%.4f" lon="5.3000"><ele>%d</ele></trkpt>', 50.0 + $i * 0.001, 100 + $i);
        }
        $path = sys_get_temp_dir().'/cc-rpf-'.bin2hex(random_bytes(4)).'.gpx';
        file_put_contents($path, '<?xml version="1.0"?><gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1"><trk><trkseg>'.$pts.'</trkseg></trk></gpx>');

        return $path;
    }

    public function testTheProposalFormCarriesThePhotoUploader(): void
    {
        $this->client->loginUser($this->rider());
        $crawler = $this->client->request('GET', '/propose-route');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('form[data-route-photos] #file-photo')->count());
        self::assertSame(1, $crawler->filter('input[name="propose_route[mediaIds]"]')->count());
        self::assertSame(1, $crawler->filter('script[src*="contribute/route-photos"]')->count());
    }

    public function testAPhotoSentWithAProposalIsClaimedByTheRoute(): void
    {
        $rider = $this->rider();
        $upload = $this->upload($rider);
        $this->client->loginUser($rider);
        $crawler = $this->client->request('GET', '/propose-route');
        $form = $crawler->filter('form[name="propose_route"]')->form();
        $form['propose_route[rName]'] = 'Photo proposal flow';
        $form['propose_route[difficulty]'] = 'Moderate';
        $form['propose_route[dominantSurface]'] = 'Asphalt';
        $form['propose_route[mediaIds]'] = json_encode([$upload->getId()->toRfc4122()], \JSON_THROW_ON_ERROR);
        $gpx = self::gpx();
        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), [
            'propose_route' => ['gpx' => new UploadedFile($gpx, 'photo.gpx', 'application/gpx+xml', null, true)],
        ]);
        @unlink($gpx);

        self::assertResponseIsSuccessful();
        $route = $this->em->getRepository(RecommendedRoute::class)->findOneBy(['name' => 'Photo proposal flow']);
        self::assertNotNull($route);
        $this->em->clear();
        self::assertSame($route->getId(), $this->em->find(MediaUpload::class, $upload->getId())?->getRouteId());
    }

    public function testALiveRouteOpensItsPhotoFormAndARouteInReviewDoesNot(): void
    {
        $rider = $this->rider();
        $live = $this->route(ItemState::Unverified, $rider);
        $waiting = $this->route(ItemState::Submitted, $rider);
        $this->client->loginUser($rider);

        $crawler = $this->client->request('GET', '/propose-route?route='.$live->getId());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($live->getName(), $crawler->filter('h1')->text());
        self::assertSame(0, $crawler->filter('input[type="file"][name="propose_route[gpx]"]')->count(), 'riders never edit a route: photos and a note only');
        self::assertSame(1, $crawler->filter('form[data-route-pin] #file-photo')->count());

        $this->client->request('GET', '/propose-route?route='.$waiting->getId());
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/propose-route?route=abc');
        self::assertResponseStatusCodeSame(404);
    }

    public function testPhotosForALiveRouteBecomeAPhotoCorrection(): void
    {
        $rider = $this->rider();
        $route = $this->route(ItemState::Verified, $rider);
        $upload = $this->upload($rider);
        $this->client->loginUser($rider);

        $crawler = $this->client->request('GET', '/propose-route?route='.$route->getId());
        $form = $crawler->filter('form[name="propose_route"]')->form();
        $form['propose_route[mediaIds]'] = json_encode([$upload->getId()->toRfc4122()], \JSON_THROW_ON_ERROR);
        $form['propose_route[note]'] = 'Taken at the lock';
        $this->client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-receipt]');
        $suggestion = $this->em->getRepository(RouteSuggestion::class)->findOneBy(['routeId' => $route->getId()]);
        self::assertNotNull($suggestion);
        self::assertSame(RouteSuggestionReason::Photo, $suggestion->getReason());
        self::assertSame('Taken at the lock', $suggestion->getNote());
    }

    public function testSendingNoPhotoIsRefusedInWords(): void
    {
        $rider = $this->rider();
        $route = $this->route(ItemState::Verified, $rider);
        $this->client->loginUser($rider);

        $crawler = $this->client->request('GET', '/propose-route?route='.$route->getId());
        $this->client->submit($crawler->filter('form[name="propose_route"]')->form());

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Add at least one photo.', (string) $this->client->getResponse()->getContent());
        self::assertSame(0, $this->em->getRepository(RouteSuggestion::class)->count(['routeId' => $route->getId()]));
    }

    public function testTheDrawerSuggestEndpointRefusesAPhotoReason(): void
    {
        $rider = $this->rider();
        $route = $this->route(ItemState::Verified, $rider);
        $this->client->loginUser($rider);
        $this->client->request('GET', '/routes/'.$route->getId().'/community');
        $token = json_decode((string) $this->client->getResponse()->getContent(), true)['token'];

        $this->client->request('POST', '/routes/'.$route->getId().'/suggest', ['_token' => $token, 'reason' => 'photo']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testTheDeskShowsThePhotosAndDoneKeepsOnlyTheTickedOnes(): void
    {
        $rider = $this->rider();
        $route = $this->route(ItemState::Verified, $rider);
        $kept = $this->upload($rider);
        $dropped = $this->upload($rider);
        $this->client->loginUser($rider);
        $crawler = $this->client->request('GET', '/propose-route?route='.$route->getId());
        $form = $crawler->filter('form[name="propose_route"]')->form();
        $form['propose_route[mediaIds]'] = json_encode([$kept->getId()->toRfc4122(), $dropped->getId()->toRfc4122()], \JSON_THROW_ON_ERROR);
        $this->client->submit($form);
        $suggestion = $this->em->getRepository(RouteSuggestion::class)->findOneBy(['routeId' => $route->getId()]);
        self::assertNotNull($suggestion);

        $this->client->loginUser($this->curator());
        $desk = $this->client->request('GET', '/moderate/routes/'.$route->getId());
        self::assertResponseIsSuccessful();
        self::assertSame(2, $desk->filter('input[name="photo_keep[]"][form="rs-done-'.$suggestion->getId().'"]')->count());

        $done = $desk->filter('#rs-done-'.$suggestion->getId())->form();
        $values = $done->getPhpValues();
        $values['photo_ids'] = [$kept->getId()->toRfc4122(), $dropped->getId()->toRfc4122()];
        $values['photo_keep'] = [$kept->getId()->toRfc4122()];
        $this->client->request('POST', $done->getUri(), $values);
        self::assertResponseRedirects();

        self::assertSame(MediaStatus::Approved, $this->uploadStatus($kept));
        self::assertSame(MediaStatus::Rejected, $this->uploadStatus($dropped));
        self::assertSame(RouteSuggestionStatus::Done, $this->em->find(RouteSuggestion::class, $suggestion->getId())?->getStatus());
        $photos = $this->em->find(RecommendedRoute::class, $route->getId())?->getAttributes()['photos'] ?? [];
        self::assertSame([$kept->getId()->toRfc4122()], array_column($photos, 'id'));
    }

    /** A proposal waiting for review, with the fields its edit form requires. */
    private function proposal(User $by, ItemState $state = ItemState::Submitted): RecommendedRoute
    {
        $r = $this->route($state, $by);
        $r->setAttributes(['difficulty' => ['score' => 2, 'label' => 'Moderate'], 'dominantSurface' => 'Asphalt']);
        $this->em->flush();

        return $r;
    }

    /** An upload already sent with `$route`'s proposal. */
    private function sentWith(RecommendedRoute $route, User $owner): MediaUpload
    {
        $upload = $this->upload($owner);
        $upload->claimForRoute((int) $route->getId(), null);
        $this->em->flush();

        return $upload;
    }

    private function claimedRoute(MediaUpload $upload): ?int
    {
        $this->em->clear();

        return $this->em->find(MediaUpload::class, $upload->getId())?->getRouteId();
    }

    /** @return array<string, mixed> the edit form's values carrying `$uploads` */
    private function editValues(RecommendedRoute $route, MediaUpload ...$uploads): array
    {
        $crawler = $this->client->request('GET', '/propose-route/'.$route->getId().'/edit');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="propose_route"]')->form();
        $form['propose_route[mediaIds]'] = json_encode(array_map(static fn (MediaUpload $u): string => $u->getId()->toRfc4122(), $uploads), \JSON_THROW_ON_ERROR);

        return ['uri' => $form->getUri(), 'values' => $form->getPhpValues()];
    }

    public function testAPhotoWithoutConsentIsRefusedAndTheProposalWithIt(): void
    {
        $rider = $this->rider();
        $this->client->loginUser($rider);
        $this->client->request('GET', '/media/token');
        $token = (string) (json_decode((string) $this->client->getResponse()->getContent(), true)['token'] ?? '');

        // The uploader's own endpoint, with the pin route-photos.js sends: no consent, no upload.
        $image = new \Imagick();
        $image->newImage(1200, 900, 'green');
        $image->setImageFormat('jpeg');
        $path = sys_get_temp_dir().'/cc-rpf-'.bin2hex(random_bytes(4)).'.jpeg';
        $image->writeImage($path);
        $image->clear();
        $this->client->request('POST', '/media/photos', ['_token' => $token, 'lat' => '50.05', 'lng' => '5.3'], [
            'photo' => new UploadedFile($path, 'ride.jpeg', 'image/jpeg', null, true),
        ]);
        @unlink($path);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('consent_required', json_decode((string) $this->client->getResponse()->getContent(), true)['error'] ?? null);
        self::assertSame(0, $this->em->getRepository(MediaUpload::class)->count(['userId' => $rider->getId()]));

        // A proposal naming a photo that was never stored is refused whole.
        $crawler = $this->client->request('GET', '/propose-route');
        $form = $crawler->filter('form[name="propose_route"]')->form();
        $form['propose_route[rName]'] = 'No consent proposal';
        $form['propose_route[difficulty]'] = 'Moderate';
        $form['propose_route[dominantSurface]'] = 'Asphalt';
        $form['propose_route[mediaIds]'] = json_encode([Uuid::v4()->toRfc4122()], \JSON_THROW_ON_ERROR);
        $gpx = self::gpx();
        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), [
            'propose_route' => ['gpx' => new UploadedFile($gpx, 'photo.gpx', 'application/gpx+xml', null, true)],
        ]);
        @unlink($gpx);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('could not be used', (string) $this->client->getResponse()->getContent());
        self::assertNull($this->em->getRepository(RecommendedRoute::class)->findOneBy(['name' => 'No consent proposal']));
    }

    public function testTheProposerEditCarriesTheUploaderAndTheSentPhotos(): void
    {
        $rider = $this->rider();
        $route = $this->proposal($rider);
        $this->sentWith($route, $rider);
        $this->client->loginUser($rider);

        $crawler = $this->client->request('GET', '/propose-route/'.$route->getId().'/edit');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('form[data-route-photos][data-route-pin] #file-photo')->count());
        self::assertSame(1, $crawler->filter('#media-consent')->count(), 'the same consent gate as a first proposal');
        self::assertSame(1, $crawler->filter('.rp-existing img')->count(), 'the photo already sent is shown');
        self::assertSame(1, $crawler->filter('script[src*="contribute/media-upload"]')->count());
        self::assertSame(1, $crawler->filter('script[src*="contribute/route-photos"]')->count());
        self::assertStringContainsString('"max":5', (string) $this->client->getResponse()->getContent(), 'the cap counts the photo already sent');
    }

    public function testTheProposerAddsAPhotoOnTheEdit(): void
    {
        $rider = $this->rider();
        $route = $this->proposal($rider);
        $sent = $this->sentWith($route, $rider);
        $added = $this->upload($rider);
        $this->client->loginUser($rider);

        $edit = $this->editValues($route, $added);
        $this->client->request('POST', $edit['uri'], $edit['values']);
        self::assertResponseRedirects('/account/contributions?letter=R#route-'.$route->getId(), 303);

        self::assertSame($route->getId(), $this->claimedRoute($added));
        $row = $this->em->find(MediaUpload::class, $added->getId());
        self::assertNull($row?->getRouteSuggestionId(), 'claimed by the proposal, decided with it');
        self::assertSame(MediaStatus::Pending, $row?->getStatus(), 'unpublished until the route is approved');
        self::assertSame($route->getId(), $this->claimedRoute($sent), 'the photo sent first stays');
        self::assertSame(ItemState::Submitted, $this->em->find(RecommendedRoute::class, $route->getId())?->getState());
    }

    public function testTheEditKeepsAProposalWithinSixPhotos(): void
    {
        $rider = $this->rider();
        $route = $this->proposal($rider);
        $this->client->loginUser($rider);
        for ($i = 0; $i < MediaClaimService::MAX_PER_SUBMISSION - 1; ++$i) {
            $this->sentWith($route, $rider);
        }
        $sixth = $this->upload($rider);
        $seventh = $this->upload($rider);

        $edit = $this->editValues($route, $sixth, $seventh);
        $this->client->request('POST', $edit['uri'], $edit['values']);
        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->claimedRoute($sixth), 'a refused save claims nothing');
        self::assertNull($this->claimedRoute($seventh));

        $edit = $this->editValues($route, $sixth);
        $this->client->request('POST', $edit['uri'], $edit['values']);
        self::assertResponseRedirects();
        self::assertSame($route->getId(), $this->claimedRoute($sixth));

        $crawler = $this->client->request('GET', '/propose-route/'.$route->getId().'/edit');
        self::assertSame(0, $crawler->filter('#file-photo')->count(), 'a full proposal offers no uploader');
        self::assertStringContainsString('already carries 6 photos', $crawler->filter('form[name="propose_route"]')->text());
    }

    public function testAnotherRiderCannotAddPhotosToSomeoneElsesProposal(): void
    {
        $owner = $this->rider();
        $route = $this->proposal($owner);
        $other = $this->rider();
        $theirs = $this->upload($other);
        $this->client->loginUser($other);

        $this->client->request('POST', '/propose-route/'.$route->getId().'/edit', ['propose_route' => [
            'rName' => 'Hijack', 'difficulty' => 'Moderate', 'dominantSurface' => 'Asphalt',
            'mediaIds' => json_encode([$theirs->getId()->toRfc4122()], \JSON_THROW_ON_ERROR),
        ]]);
        self::assertResponseStatusCodeSame(404);
        self::assertNull($this->claimedRoute($theirs));

        // The service refuses it on its own, whatever the controller does.
        $threw = false;
        try {
            static::getContainer()->get(RouteProposalService::class)->revise((int) $route->getId(), null, [
                'rName' => 'Hijack', 'mediaIds' => json_encode([$theirs->getId()->toRfc4122()], \JSON_THROW_ON_ERROR),
            ], $other);
        } catch (NotTheSubmitterException) {
            $threw = true;
        }
        self::assertTrue($threw);
        self::assertNull($this->claimedRoute($theirs));
    }

    public function testADecidedRouteGetsNoPhotosThroughTheEditPath(): void
    {
        $rider = $this->rider();
        $route = $this->proposal($rider, ItemState::Unverified);
        $upload = $this->upload($rider);
        $this->client->loginUser($rider);

        $this->client->request('POST', '/propose-route/'.$route->getId().'/edit', ['propose_route' => [
            'rName' => 'Late', 'difficulty' => 'Moderate', 'dominantSurface' => 'Asphalt',
            'mediaIds' => json_encode([$upload->getId()->toRfc4122()], \JSON_THROW_ON_ERROR),
        ]]);
        self::assertResponseRedirects('/account/contributions?letter=R#route-'.$route->getId(), 303);
        self::assertNull($this->claimedRoute($upload));

        // An edit racing the decision: the locked re-read refuses it, photos included.
        $threw = false;
        try {
            static::getContainer()->get(RouteProposalService::class)->revise((int) $route->getId(), null, [
                'rName' => 'Late', 'mediaIds' => json_encode([$upload->getId()->toRfc4122()], \JSON_THROW_ON_ERROR),
            ], $rider);
        } catch (AlreadyDecidedException) {
            $threw = true;
        }
        self::assertTrue($threw);
        self::assertNull($this->claimedRoute($upload));
    }
}
