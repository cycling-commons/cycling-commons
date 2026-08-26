<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Catalog\Entity\Item;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Controller\RideCheckController;
use App\Entity\User;
use App\Tests\Coverage\CoverageSchema;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * POST /map/ride-check (spec 2026-07-14 §4.1): the stateless JSON intake for
 * the ride-check — in-controller auth (clean 401), stateless CSRF, per-user
 * daily limiter, translated validation errors, nothing persisted.
 */
final class RideCheckControllerTest extends WebTestCase
{
    use CoverageSchema;

    /** @var list<string> temp upload files to unlink after each test */
    private static array $tmpFiles = [];

    #[\Override]
    protected function tearDown(): void
    {
        foreach (self::$tmpFiles as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        self::$tmpFiles = [];
        parent::tearDown();
    }

    private static function tmpUpload(string $suffix, string $content): string
    {
        $base = (string) tempnam(sys_get_temp_dir(), 'cc-');
        $path = $base.$suffix;
        @unlink($base);
        file_put_contents($path, $content);
        self::$tmpFiles[] = $path;

        return $path;
    }

    /** A ~2.1 km straight test ride at lat 50.4 (see RideCheckServiceTest). */
    private static function gpxFixture(): UploadedFile
    {
        $pts = '';
        foreach (range(0, 6) as $i) {
            $pts .= sprintf('<trkpt lat="50.400000" lon="%.6F"><ele>%d</ele></trkpt>', 5.8 + 0.005 * $i, 100 + 5 * $i);
        }
        $path = self::tmpUpload('.gpx', '<?xml version="1.0"?><gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1"><trk><trkseg>'.$pts.'</trkseg></trk></gpx>');

        return new UploadedFile($path, 'ride.gpx', 'application/gpx+xml', null, true);
    }

    private static function user(string $email): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail($email);
        $user->setPassword('x');
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private static function token(): string
    {
        return static::getContainer()->get(CsrfTokenManagerInterface::class)->getToken('ride-check')->getValue();
    }

    /** @return array{0: array<string, string>, 1: array<string, UploadedFile>, 2: array<string, string>} params/files/server for a valid POST */
    private static function post(?int $radius = null): array
    {
        $params = ['_token' => self::token()];
        if (null !== $radius) {
            $params['radius'] = (string) $radius;
        }

        return [$params, ['gpx' => self::gpxFixture()], ['HTTP_SEC_FETCH_SITE' => 'same-origin']];
    }

    public function testAnonymousCanCheckARide(): void
    {
        $client = static::createClient();
        // coverage_poi is pipeline-owned DDL outside Doctrine's migrations, and
        // check() reads it unconditionally — without the table this is a 500.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::ensureCoverageSchema($em->getConnection());

        [$params, $files, $server] = self::post();
        $client->request('POST', '/map/ride-check', $params, $files, $server);
        self::assertResponseIsSuccessful();
    }

    public function testAnonymousOverFiveIsRefusedAndTownAnAccount(): void
    {
        $client = static::createClient();
        $secret = (string) static::getContainer()->getParameter('kernel.secret');

        // Same draining trick as the signed-in case below: the array pool
        // resets between HTTP requests, so consume the allowance directly and
        // let the one real request be the sixth.
        $factory = static::getContainer()->get('limiter.ride_check_anon');
        $limiter = $factory->create(RideCheckController::anonKey('127.0.0.1', $secret));
        for ($i = 0; $i < 5; ++$i) {
            self::assertTrue($limiter->consume()->isAccepted());
        }

        [$params, $files, $server] = self::post();
        $client->request('POST', '/map/ride-check', $params, $files, $server);
        self::assertResponseStatusCodeSame(429);

        // The refusal has to say why and what to do about it.
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('free account', $body);
        self::assertStringContainsString('5 ride checks', $body);
    }

    public function testTheAnonymousKeyNeverContainsTheAddress(): void
    {
        $key = RideCheckController::anonKey('203.0.113.7', 'test-secret');
        self::assertStringNotContainsString('203.0.113.7', $key);
        self::assertNotSame($key, RideCheckController::anonKey('203.0.113.8', 'test-secret'));
        self::assertNotSame($key, RideCheckController::anonKey('203.0.113.7', 'other-secret'));
    }

    public function testBadCsrfIs403(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('ride-check-csrf@test.test'));
        $client->request('POST', '/map/ride-check', ['_token' => 'nope'], ['gpx' => self::gpxFixture()], ['HTTP_SEC_FETCH_SITE' => 'cross-site']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testMissingFileIs422(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('ride-check-nofile@test.test'));
        $client->request('POST', '/map/ride-check', ['_token' => self::token()], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertResponseStatusCodeSame(422);
        /** @var array{error: string} $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertNotSame('', $body['error']);
    }

    /**
     * A file PHP itself rejected (over upload_max_filesize) is NOT "no file
     * chosen": the rider picked one, so telling them to pick one is a dead end
     * that hides a size problem they can act on. Reproduced from the browser
     * with a 4.5 MB Strava export against the shipped 16 MB limit's predecessor.
     */
    public function testRejectedUploadReportsTheRealReason(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('ride-check-toobig@test.test'));
        $path = self::tmpUpload('.gpx', '<gpx/>');
        // test:false keeps the UploadedFile's error code intact, so this is the
        // exact object PHP hands the controller on UPLOAD_ERR_INI_SIZE.
        $tooBig = new UploadedFile($path, 'ride.gpx', 'application/gpx+xml', \UPLOAD_ERR_INI_SIZE, false);
        $client->request('POST', '/map/ride-check', ['_token' => self::token()], ['gpx' => $tooBig], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertResponseStatusCodeSame(422);
        /** @var array{error: string} $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true);

        $nofile = static::getContainer()->get('translator')->trans('ride_check.error.file_required');
        self::assertNotSame($nofile, $body['error'], 'an oversized upload must not be reported as "choose a file"');
        self::assertStringContainsStringIgnoringCase('large', $body['error']);
    }

    public function testInvalidRadiusIs422(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('ride-check-radius@test.test'));
        [$params, $files, $server] = self::post(999);
        $client->request('POST', '/map/ride-check', $params, $files, $server);
        self::assertResponseStatusCodeSame(422);
    }

    public function testHappyPathReturnsCorridorPayload(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        // check() reads coverage_poi unconditionally since the coverage arm
        // landed, and that table
        // is pipeline-owned DDL outside Doctrine's migrations — so a controller
        // test that does not build it gets a 500, not a payload.
        self::ensureCoverageSchema($em->getConnection());
        self::insertCoveragePoi($em->getConnection(), [
            'letter' => 'B', 'name' => 'OSM fontaine', 'lat' => 50.40045, 'lng' => 5.8050, 'ref' => 'node/rc-web-cov',
        ]);
        $item = (new Item())->setLetter('B')->setName('Fontaine du test')
            ->setGeom(json_encode(['type' => 'Point', 'coordinates' => [5.815, 50.40045]], \JSON_THROW_ON_ERROR))
            ->setCountryCode('BE')->setState(ItemState::Verified)->setSource(ItemSource::Osm)
            ->setSourceRef('node/rc-web')->setAttributes([]);
        $em->persist($item);
        $em->flush();

        $client->loginUser(self::user('ride-check-happy@test.test'));
        [$params, $files, $server] = self::post();
        $client->request('POST', '/map/ride-check', $params, $files, $server);

        self::assertResponseIsSuccessful();
        /** @var array{track: list<array{0: float, 1: float}>, distanceKm: float, radiusM: int, groups: list<array{letter: string, items: list<array{name: string}>}>, coverage: list<array{letter: string, items: list<array{name: string, ref: string}>}>, routes: list<mixed>} $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(250, $body['radiusM']);
        self::assertGreaterThan(1.5, $body['distanceKm']);
        self::assertNotEmpty($body['track']);
        $letters = array_column($body['groups'], 'letter');
        self::assertContains('B', $letters);
        self::assertSame('Fontaine du test', $body['groups'][array_search('B', $letters, true)]['items'][0]['name']);
        // The coverage arm is serialized alongside the curated one, each item
        // carrying the `ref` the frontend needs to open it (§3.1/§3.3).
        self::assertArrayHasKey('coverage', $body);
        $covLetters = array_column($body['coverage'], 'letter');
        self::assertContains('B', $covLetters);
        $covItem = $body['coverage'][array_search('B', $covLetters, true)]['items'][0];
        self::assertSame('OSM fontaine', $covItem['name']);
        self::assertSame('node/rc-web-cov', $covItem['ref']);
        // Read-only: the upload must not create any DB row.
        self::assertSame(0, (int) $em->getConnection()->fetchOne("SELECT COUNT(*) FROM recommended_route WHERE source_ref LIKE 'user:%'"));
    }

    public function testOverDailyLimitIs429(): void
    {
        $client = static::createClient();
        $user = self::user('ride-check-limit@test.test');

        // Drain the limiter directly (RouteSuggestFlowTest convention: the
        // array cache pool resets on every kernel reboot between HTTP
        // requests, so 21 real requests would never trip it — consume 20 via
        // the factory, then let the single HTTP request be the 21st).
        $factory = static::getContainer()->get('limiter.ride_check');
        $limiter = $factory->create('user-'.(string) $user->getId());
        for ($i = 0; $i < 20; ++$i) {
            self::assertTrue($limiter->consume()->isAccepted());
        }

        $client->loginUser($user);
        [$params, $files, $server] = self::post();
        $client->request('POST', '/map/ride-check', $params, $files, $server);
        self::assertResponseStatusCodeSame(429);
    }
}
