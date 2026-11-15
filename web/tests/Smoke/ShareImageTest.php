<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The link-preview picture (og:image, twitter:image) must be a URL the site serves.
 *
 * Both pointed at /brand/og/*.jpg, a folder nothing serves: every shared link had
 * no picture and every preview crawler left a 404 in GlitchTip (2026-11-14).
 */
final class ShareImageTest extends WebTestCase
{
    /** @return iterable<string, array{string}> */
    public static function pages(): iterable
    {
        yield 'home (head partial default)' => ['/'];
        yield 'map (own head)' => ['/map'];
    }

    #[DataProvider('pages')]
    public function testEveryShareImageIsAbsoluteAndServed(string $page): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', $page);
        self::assertResponseIsSuccessful();

        $urls = $crawler->filter('meta[property="og:image"], meta[name="twitter:image"]')->extract(['content']);
        self::assertNotEmpty($urls, 'a page with no share image');

        foreach ($urls as $url) {
            self::assertMatchesRegularExpression('#^https?://#', $url, 'crawlers need an absolute URL');

            $client->request('GET', (string) parse_url($url, \PHP_URL_PATH));
            self::assertResponseIsSuccessful(sprintf('%s on %s', $url, $page));
            self::assertResponseHeaderSame('Content-Type', 'image/jpeg');
        }
    }

    /** @return iterable<string, array{0: string, 1: string, 2?: bool}> */
    public static function cards(): iterable
    {
        yield 'home' => ['/', 'index'];
        yield 'map' => ['/map', 'map'];
        yield 'about' => ['/about', 'about'];
        yield 'contributors' => ['/contributors', 'contributors'];
        yield 'coverage' => ['/coverage', 'coverage'];
        yield 'developers' => ['/developers', 'developers'];
        yield 'licenses' => ['/licenses', 'licenses'];
        yield 'pages' => ['/pages', 'pages'];
        yield 'regions' => ['/regions', 'region'];
        yield 'improve (signed in)' => ['/improve', 'improve', true];
        yield 'add a climb (signed in)' => ['/improve?type=climbs&mode=add', 'add-climb', true];
        yield 'login' => ['/login', 'login'];
        yield 'best of the season' => ['/best', 'best-of'];
        yield 'scout' => ['/scout', 'scout'];
        yield 'get involved' => ['/join', 'join'];
        yield 'contribute' => ['/contribute', 'contribute'];
        yield 'blog' => ['/blog', 'blog'];
        yield 'roadmap' => ['/roadmap', 'roadmap'];
        yield 'a page with no card shares the home card' => ['/contact', 'index'];
    }

    /** Each page shares the card that says what it does, with that text as its alt. */
    #[DataProvider('cards')]
    public function testEachPageSharesItsOwnCard(string $page, string $card, bool $signedIn = false): void
    {
        $client = static::createClient();
        if ($signedIn) {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            $u = (new User())->setEmail(sprintf('og-%s@test.test', $card))->setDisplayName('og-'.$card);
            $u->setEmailVerified(true)->setEmailVerifiedAt(new \DateTimeImmutable())->setRoles([]);
            $u->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($u, 'password1234'));
            $em->persist($u);
            $em->flush();
            $client->loginUser($u);
        }
        $crawler = $client->request('GET', $page);
        self::assertResponseIsSuccessful();

        $url = $crawler->filter('meta[property="og:image"]')->attr('content');
        self::assertMatchesRegularExpression(sprintf('#/brand/og/%s-[^/]+\.jpg$#', preg_quote($card, '#')), (string) $url);
        self::assertNotSame('', trim((string) $crawler->filter('meta[property="og:image:alt"]')->attr('content')));
    }
}
