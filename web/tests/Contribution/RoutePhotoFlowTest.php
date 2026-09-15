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
use App\Entity\User;
use App\Media\Entity\ConsentRecord;
use App\Media\Entity\MediaUpload;
use App\Media\MediaConsent;
use App\Media\MediaStatus;
use App\Media\MediaStorage;
use App\Media\ProcessedPhoto;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Photos on /propose-route (docs/specs/route-domain.md §4.5,
 * photo-uploads.md §5i): with a new proposal, and for a live route through
 * `?route=<id>`; decided on the Routes desk with per-photo Keep boxes.
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
}
