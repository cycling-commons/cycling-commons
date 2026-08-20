<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Tests\Controller;

use App\Catalog\MapTheme;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Map light/dark theme: the chrome-theme choice rides the page render as a
 * data-map-theme attribute on <html> (so the first paint is already right),
 * and a logged-in rider's choice persists to their PROFILE via /map/theme,
 * exactly like the view mode (MapPrefsTest). Anonymous visitors keep theirs
 * in localStorage; the server always hands them the dark default.
 *
 * Test isolation: DAMA\DoctrineTestBundle wraps each test in a rolled-back
 * transaction.
 */
final class MapThemeTest extends WebTestCase
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

    /**
     * The server-rendered attribute is the anti-flash mechanism: CSS keys the
     * light palette on it, so the theme is right before any JS runs. Anonymous
     * visitors always render dark server-side (their light choice, if any,
     * lives in localStorage and is applied by the inline head script).
     */
    public function testHtmlTagCarriesTheProfileTheme(): void
    {
        $client = static::createClient();
        $plain = $this->createUser('map-theme@example.com', 'securepass12345!', 'Map Theme Rider');

        $client->request('GET', '/map');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('data-map-theme="dark"', (string) $client->getResponse()->getContent());

        $this->loginAs($client, 'map-theme@example.com', $plain);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'map-theme@example.com']);
        self::assertInstanceOf(User::class, $user);
        $user->setMapTheme(MapTheme::Light);
        $em->flush();

        $client->request('GET', '/map');
        self::assertStringContainsString('data-map-theme="light"', (string) $client->getResponse()->getContent());
    }

    /**
     * The persist endpoint behind the map's sun/moon toggle. Same contract as
     * /map/view-mode: clean 401 for anonymous fetch() callers, stateless CSRF,
     * 422 for a value outside the enum.
     */
    public function testThemeEndpointStoresTheRidersChoice(): void
    {
        $client = static::createClient();
        $plain = $this->createUser('map-theme-post@example.com', 'securepass12345!', 'Map Theme Poster');

        // Anonymous: a clean 401, never an HTML login redirect the map would
        // try to parse as JSON.
        $client->request('POST', '/map/theme', ['theme' => 'light']);
        self::assertResponseStatusCodeSame(401);

        $this->loginAs($client, 'map-theme-post@example.com', $plain);
        $client->request('GET', '/map');
        $html = (string) $client->getResponse()->getContent();
        // Read the token out of the page the way the browser does — stateless
        // same-origin CSRF, so a container-minted token has no session to live in.
        self::assertSame(1, preg_match('/window\.CC_MAP_THEME = \{.*?token:\s*"([^"]+)"/s', $html, $m),
            'the riders-only block carries a map-theme token');
        $token = $m[1];

        $client->request('POST', '/map/theme', ['theme' => 'light', '_token' => $token],
            [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertResponseIsSuccessful();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'map-theme-post@example.com']);
        self::assertInstanceOf(User::class, $user);
        self::assertSame(MapTheme::Light, $user->getMapTheme());

        // A value outside the enum is a 422, not a silent no-op that leaves
        // the rider thinking their choice was saved.
        $client->request('POST', '/map/theme', ['theme' => 'sepia', '_token' => $token],
            [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertResponseStatusCodeSame(422);
    }
}
