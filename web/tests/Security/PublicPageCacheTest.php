<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The public pages may be held by a shared cache; nothing else may
 * (docs/specs/page-caching.md §2).
 *
 * The measurement that prompted this: 400 requests to `/` in 61 seconds, 400
 * answers, no limit anywhere, and every one of them reaching PHP because the
 * page said `private`. A rate limit is the wrong answer, because it does
 * nothing against many addresses and punishes riders who refresh. A page that
 * is identical for every logged-out visitor should be rendered once a minute.
 *
 * What this file exists to stop is the obvious way that goes wrong: a page with
 * a rider's own name in the nav being stored and handed to somebody else.
 * {@see testASignedInRiderNeverGetsAPublicPage} is the assertion that matters;
 * the rest describe the boundary around it.
 */
final class PublicPageCacheTest extends WebTestCase
{
    private function createUser(KernelBrowser $client, string $email, string $plain): void
    {
        $container = static::getContainer();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Cache Probe');
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles([]);
        $user->setPassword($hasher->hashPassword($user, $plain));
        $em->persist($user);
        $em->flush();

        $crawler = $client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        $client->submit($crawler->selectButton('Sign in')->form([
            '_username' => $email,
            '_password' => $plain,
        ]));
        self::assertResponseRedirects();
        $client->followRedirect();
    }

    private function cacheControl(KernelBrowser $client): string
    {
        return (string) $client->getResponse()->headers->get('Cache-Control');
    }

    #[DataProvider('cacheablePaths')]
    public function testAPublicPageMayBeSharedForAMinute(string $path): void
    {
        $client = static::createClient();
        $client->request('GET', $path);

        self::assertResponseIsSuccessful();
        $cc = $this->cacheControl($client);
        self::assertStringContainsString('public', $cc, "{$path} must be shareable");
        self::assertStringContainsString('s-maxage=60', $cc, "{$path} must name a shared lifetime");
        // The browser still revalidates: only the cache in front keeps a copy,
        // so a rider's own back button never shows a stale page.
        self::assertStringContainsString('max-age=0', $cc);
        self::assertStringContainsString('must-revalidate', $cc);
    }

    /** @return iterable<string, array{string}> */
    public static function cacheablePaths(): iterable
    {
        yield 'home' => ['/'];
        yield 'about' => ['/about'];
        yield 'developers' => ['/developers'];
        yield 'privacy' => ['/privacy'];
        yield 'regions' => ['/regions'];
        yield 'blog' => ['/blog'];
        // The locale arms are separate routes; the allowlist names each page
        // once and the suffix is stripped, so this proves the stripping works.
        yield 'french home' => ['/fr/'];
        yield 'german about' => ['/de/ueber-uns'];
    }

    /**
     * The one that matters. Everything else here is the boundary around it.
     *
     * A signed-in rider's nav carries their display name and, for a curator,
     * links nobody else may see. If that response were ever marked shareable, a
     * cache in front would be entitled to store it and hand it to the next
     * visitor. It must stay private no matter which page is asked for.
     */
    public function testASignedInRiderNeverGetsAPublicPage(): void
    {
        $client = static::createClient();
        $this->createUser($client, 'cache-probe@example.com', 'securepass12345!');

        foreach (['/', '/about', '/regions', '/blog'] as $path) {
            $client->request('GET', $path);
            self::assertResponseIsSuccessful();
            $cc = $this->cacheControl($client);
            self::assertStringContainsString('private', $cc, "{$path} was shareable for a signed-in rider");
            self::assertStringNotContainsString('public', $cc, "{$path} was shareable for a signed-in rider");
            self::assertStringNotContainsString('s-maxage', $cc, "{$path} named a shared lifetime for a signed-in rider");
        }
    }

    /**
     * A page whose content depends on the visitor, or that moves under them,
     * stays out. `/coverage` belongs in the set and is held back only until its
     * inline sort script becomes a file (page-caching.md §6).
     */
    #[DataProvider('privatePaths')]
    public function testEverythingElseStaysPrivate(string $path): void
    {
        $client = static::createClient();
        $client->request('GET', $path);

        self::assertResponseIsSuccessful();
        $cc = $this->cacheControl($client);
        self::assertStringContainsString('private', $cc, "{$path} must not be shareable");
        self::assertStringNotContainsString('s-maxage', $cc, "{$path} must not name a shared lifetime");
    }

    /** @return iterable<string, array{string}> */
    public static function privatePaths(): iterable
    {
        yield 'map' => ['/map'];
        yield 'contributors' => ['/contributors'];
        yield 'coverage' => ['/coverage'];
        yield 'contact form' => ['/contact'];
        yield 'login' => ['/login'];
    }

    /**
     * A challenge is single use, so it must never be stored by anything. Guarded
     * in its own test file too; repeated here because this subscriber is what
     * hands out `public`, and a bug here is how it would leak.
     */
    public function testTheChallengeEndpointIsNeverMadePublic(): void
    {
        $client = static::createClient();
        $client->request('GET', '/report-bug/challenge', server: ['HTTP_ACCEPT' => 'application/json']);

        $cc = $this->cacheControl($client);
        self::assertStringContainsString('no-store', $cc);
        self::assertStringNotContainsString('s-maxage', $cc);
    }

    /** The shared boot script is public on purpose: it is the same for everybody. */
    public function testTheBootScriptIsShareable(): void
    {
        $client = static::createClient();
        $client->request('GET', '/boot.js');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('public', $this->cacheControl($client));
        self::assertSame('application/javascript; charset=UTF-8',
            $client->getResponse()->headers->get('Content-Type'));
    }
}
