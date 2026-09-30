<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Routing;

use App\Controller\SocialLinkController;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The social profiles' short links: each one a public 302 to the home page
 * with its UTM parameters (security-architecture.md §2.2).
 *
 * Two-letter paths share their shape with the locale prefixes (`/fr`, `/nl`),
 * so these also pin that `/bs`, `/li`, `/ig` and `/yt` reach the redirect
 * rather than a 404 or a locale redirect.
 */
final class SocialLinkTest extends WebTestCase
{
    private const array EXPECTED = [
        '/m' => '/?utm_source=mastodon&utm_medium=social',
        '/bs' => '/?utm_source=bluesky&utm_medium=social',
        '/li' => '/?utm_source=linkedin&utm_medium=social',
        '/ig' => '/?utm_source=instagram&utm_medium=social',
        '/yt' => '/?utm_source=youtube&utm_medium=social',
        '/r' => '/?utm_source=reddit&utm_medium=social',
    ];

    public function testEachShortLinkRedirectsHomeWithItsUtmParameters(): void
    {
        $client = static::createClient();
        foreach (self::EXPECTED as $path => $target) {
            $client->request('GET', $path);
            self::assertResponseStatusCodeSame(302, $path);
            self::assertSame($target, $client->getResponse()->headers->get('Location'), $path);
        }
    }

    public function testTheRedirectIsPublicAndStartsNoSession(): void
    {
        $client = static::createClient();
        foreach (array_keys(self::EXPECTED) as $path) {
            $client->request('GET', $path);
            $response = $client->getResponse();
            self::assertTrue($response->headers->hasCacheControlDirective('public'), $path.' may be held by a shared cache');
            self::assertSame('3600', $response->headers->getCacheControlDirective('max-age'), $path);
            self::assertSame([], $response->headers->getCookies(), $path.' sets no cookie');
            self::assertNull($client->getCookieJar()->get('PHPSESSID'), $path.' starts no session');
        }
    }

    public function testTheTargetAnswers(): void
    {
        $client = static::createClient();
        $client->request('GET', '/bs');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function testAnUnknownShortPathStaysA404(): void
    {
        $client = static::createClient();
        foreach (['/x', '/tw', '/fb', '/mm', '/fr/bs', '/M'] as $path) {
            $client->request('GET', $path);
            self::assertResponseStatusCodeSame(404, $path);
        }
    }

    public function testThePatternMatchesTheList(): void
    {
        self::assertSame(implode('|', array_keys(SocialLinkController::LINKS)), SocialLinkController::CODE_PATTERN);
        self::assertSame(
            array_map(static fn (string $code): string => '/'.$code, array_keys(SocialLinkController::LINKS)),
            array_keys(self::EXPECTED),
        );
    }
}
