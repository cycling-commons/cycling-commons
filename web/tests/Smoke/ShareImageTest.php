<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Smoke;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

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
}
