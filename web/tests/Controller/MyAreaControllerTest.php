<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Catalog\Entity\Region;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * POST /map/my-area (region-scoping-design.md §4/§6): the rider "set my area"
 * write path. Coarsens+clamps+derives via BaseLocationService (never echoes
 * raw input — response carries the re-read STORED values only, per §4's
 * request-precision-equals-stored-precision invariant), stateless CSRF
 * (`my-area` token id, header-carried like the ride-check upload), 400 on
 * out-of-range coordinates before the entity is touched.
 */
final class MyAreaControllerTest extends WebTestCase
{
    private function makeRegion(EntityManagerInterface $em): Region
    {
        $region = (new Region())
            ->setSlug('my-area-test-'.bin2hex(random_bytes(4)))
            ->setName('My area test box')
            ->setCountryCode('BE')
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[4.70,50.30],[5.00,50.30],[5.00,50.60],[4.70,50.60],[4.70,50.30]]]]}');
        $em->persist($region);
        $em->flush();

        return $region;
    }

    private function makeUser(EntityManagerInterface $em, string $email): User
    {
        $user = (new User())->setEmail($email)->setPassword('x');
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function token(): string
    {
        return static::getContainer()->get(CsrfTokenManagerInterface::class)->getToken('my-area')->getValue();
    }

    public function testAnonymousCannotSetMyArea(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/map/my-area',
            [],
            [],
            ['HTTP_X_CSRF_TOKEN' => $this->token(), 'CONTENT_TYPE' => 'application/json', 'HTTP_SEC_FETCH_SITE' => 'same-origin'],
            (string) json_encode(['lat' => 50.45, 'lng' => 4.85]),
        );

        $status = $client->getResponse()->getStatusCode();
        self::assertTrue(
            \in_array($status, [401, 403], true) || $client->getResponse()->isRedirect(),
            'expected anonymous POST to be rejected (401/403) or redirected to login, got '.$status,
        );
    }

    public function testSetFromPointCoarsensDerivesAndReturnsSet(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $region = $this->makeRegion($em);
        $user = $this->makeUser($em, 'my-area-set@example.test');
        $client->loginUser($user);

        $client->request(
            'POST',
            '/map/my-area',
            [],
            [],
            ['HTTP_X_CSRF_TOKEN' => $this->token(), 'CONTENT_TYPE' => 'application/json', 'HTTP_SEC_FETCH_SITE' => 'same-origin'],
            (string) json_encode(['lat' => 50.451234, 'lng' => 4.851234, 'radiusKm' => 55, 'place' => 'Namur']),
        );

        self::assertResponseIsSuccessful();
        /** @var array{lat: float, lng: float, radiusKm: int, place: ?string, regionIds: list<int>, countryCodes: list<string>} $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(50.45, $body['lat']);
        self::assertSame(4.85, $body['lng']);
        self::assertSame(55, $body['radiusKm']);
        self::assertSame('Namur', $body['place']);
        self::assertSame([$region->getId()], $body['regionIds']);
        self::assertSame(['BE'], $body['countryCodes']);

        $em->clear();
        $reloaded = $em->getRepository(User::class)->find($user->getId());
        self::assertNotNull($reloaded);
        self::assertSame(50.45, $reloaded->getBaseLat());
        self::assertSame(4.85, $reloaded->getBaseLng());
        self::assertSame([$region->getId()], $reloaded->getBaseRegionIds());
        self::assertSame(['BE'], $reloaded->getBaseCountryCodes());
    }

    public function testBadCsrfRejected(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->makeUser($em, 'my-area-csrf@example.test');
        $client->loginUser($user);

        $client->request(
            'POST',
            '/map/my-area',
            [],
            [],
            ['HTTP_X_CSRF_TOKEN' => 'not-a-valid-token', 'CONTENT_TYPE' => 'application/json', 'HTTP_SEC_FETCH_SITE' => 'cross-site'],
            (string) json_encode(['lat' => 50.45, 'lng' => 4.85]),
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testOutOfRangeCoordsRejected(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->makeUser($em, 'my-area-range@example.test');
        $client->loginUser($user);

        $client->request(
            'POST',
            '/map/my-area',
            [],
            [],
            ['HTTP_X_CSRF_TOKEN' => $this->token(), 'CONTENT_TYPE' => 'application/json', 'HTTP_SEC_FETCH_SITE' => 'same-origin'],
            (string) json_encode(['lat' => 95.0, 'lng' => 4.85]),
        );

        self::assertResponseStatusCodeSame(400);

        $em->clear();
        $reloaded = $em->getRepository(User::class)->find($user->getId());
        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->hasBaseLocation(), 'malformed input must never touch the entity');
    }

    public function testMapPagePayloadAbsentForAnon(): void
    {
        $client = static::createClient();
        $client->request('GET', '/map');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('CC_MY_AREA', (string) $client->getResponse()->getContent());
    }

    public function testMapPagePayloadPresentForUser(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->makeUser($em, 'my-area-payload@example.test');
        $client->loginUser($user);

        $client->request('GET', '/map');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('CC_MY_AREA', (string) $client->getResponse()->getContent());
    }
}
