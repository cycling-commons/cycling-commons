<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Tests\Controller;

use App\Catalog\BikeType;
use App\Catalog\MapViewMode;
use App\Catalog\RidingStyle;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * window.CC_PREFS emission on the map page (map prefilter spec 2026-07-14):
 * a logged-in rider's saved preferences ride the page render; anonymous
 * visitors get empty lists (zero behaviour change).
 *
 * Test isolation: DAMA\DoctrineTestBundle wraps each test in a rolled-back
 * transaction.
 */
final class MapPrefsTest extends WebTestCase
{
    private function createUser(string $email, string $plain, string $displayName): string
    {
        $container = static::getContainer();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName($displayName);
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles([]);
        $user->setBikeTypes([BikeType::Gravel, BikeType::Handbike]);
        $user->setRidingStyles([RidingStyle::Bikepacking]);
        $user->setPassword($hasher->hashPassword($user, $plain));

        $em->persist($user);
        $em->flush();

        return $plain;
    }

    private function loginAs(KernelBrowser $client, string $email, string $plain): void
    {
        $crawler = $client->request('GET', '/login');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Sign in')->form([
            '_username' => $email,
            '_password' => $plain,
        ]);
        $client->submit($form);

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function testAnonymousGetsEmptyPrefs(): void
    {
        $client = static::createClient();
        $client->request('GET', '/map');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('window.CC_PREFS', $html);
        // mapMode/authed joined the payload with the view-mode default;
        // the two lists are
        // still empty, which is what this test is about.
        self::assertStringContainsString('{"uid":null,"bikes":[],"styles":[],"mapMode":"auto","authed":false}', $html);
    }

    /**
     * The view-mode default.
     * 'auto' hands the decision to the region; anonymous visitors can only ever
     * be 'auto', because there is no profile to store anything else on.
     */
    public function testMapModeRidesThePrefsPayload(): void
    {
        $client = static::createClient();
        $plain = $this->createUser('map-mode@example.com', 'securepass12345!', 'Map Mode Rider');

        // Anonymous: auto + not authed, so the client may consult localStorage.
        $client->request('GET', '/map');
        self::assertStringContainsString('"mapMode":"auto","authed":false', (string) $client->getResponse()->getContent());

        $this->loginAs($client, 'map-mode@example.com', $plain);
        $client->request('GET', '/map');
        // A rider defaults to auto too, but authed:true — which is what stops
        // the client falling through to a shared device's localStorage.
        self::assertStringContainsString('"mapMode":"auto","authed":true', (string) $client->getResponse()->getContent());

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'map-mode@example.com']);
        self::assertInstanceOf(User::class, $user);
        $user->setDefaultMapMode(MapViewMode::Everything);
        $em->flush();

        $client->request('GET', '/map');
        self::assertStringContainsString('"mapMode":"everything","authed":true', (string) $client->getResponse()->getContent());
    }

    /**
     * The persist endpoint behind the map's own toggle. It speaks the TOGGLE's
     * tokens ('curated' | 'all'), not the enum's values — see
     * MapViewMode::clientToken() for why those differ.
     */
    public function testViewModeEndpointStoresTheRidersChoice(): void
    {
        $client = static::createClient();
        $plain = $this->createUser('map-mode-post@example.com', 'securepass12345!', 'Map Mode Poster');

        // Anonymous: a clean 401, never an HTML login redirect the map would
        // try to parse as JSON.
        $client->request('POST', '/map/view-mode', ['mode' => 'all']);
        self::assertResponseStatusCodeSame(401);

        $this->loginAs($client, 'map-mode-post@example.com', $plain);
        $client->request('GET', '/map');
        $html = (string) $client->getResponse()->getContent();
        // Read the token out of the page the way the browser does, rather than
        // minting one from the container: this app uses stateless same-origin
        // CSRF, so a token asked for outside a request has no session to live in.
        self::assertSame(1, preg_match('/window\.CC_MAP_MODE = \{.*?token:\s*"([^"]+)"/s', $html, $m),
            'the riders-only block carries a view-mode token');
        $token = $m[1];

        $client->request('POST', '/map/view-mode', ['mode' => 'curated', '_token' => $token],
            [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertResponseIsSuccessful();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'map-mode-post@example.com']);
        self::assertInstanceOf(User::class, $user);
        self::assertSame(MapViewMode::Curated, $user->getDefaultMapMode());

        // An unknown token is a 422, not a silent no-op that leaves the rider
        // thinking their choice was saved.
        $client->request('POST', '/map/view-mode', ['mode' => 'nonsense', '_token' => $token],
            [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testLoggedInRiderGetsSavedPrefs(): void
    {
        $client = static::createClient();
        $plain = $this->createUser('map-prefs@example.com', 'securepass12345!', 'Map Prefs Rider');
        $this->loginAs($client, 'map-prefs@example.com', $plain);

        $client->request('GET', '/map');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('"bikes":["Gravel","Handbike"]', $html);
        self::assertStringContainsString('"styles":["Bikepacking"]', $html);
    }
}
