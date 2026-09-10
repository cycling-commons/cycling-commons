<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ScoutPageTest extends WebTestCase
{
    private const string GARMIN_URL = 'https://apps.garmin.com/apps/7849af70-3769-4143-bea7-8c1ea5e5f847';

    /** @return iterable<string, array{string}> */
    public static function locales(): iterable
    {
        yield 'en' => [''];
        yield 'fr' => ['/fr'];
        yield 'nl' => ['/nl'];
        yield 'de' => ['/de'];
        yield 'es' => ['/es'];
    }

    #[DataProvider('locales')]
    public function testThePageRendersInEveryLocale(string $prefix): void
    {
        $client = static::createClient();
        $client->request('GET', $prefix.'/scout');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('h1');
    }

    public function testGarminLinksToTheRealStoreListing(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/scout');

        self::assertResponseIsSuccessful();
        self::assertCount(
            1,
            $crawler->filter('a[href="'.self::GARMIN_URL.'"]'),
            'the Garmin CTA must point at the published app, not a placeholder',
        );
    }

    /**
     * The go-live gate's prototype-honesty rule, pinned: a platform that has not
     * shipped must not look clickable. Android is "coming soon" and the Karoo is
     * merely planned — rendering either as a link would be a CTA leading
     * nowhere, which is exactly the fake-mechanism pattern the gate exists to
     * catch, and it fails silently because a dead-looking link still looks like
     * a link.
     */
    public function testUnreleasedPlatformsAreNotLinks(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/scout');

        self::assertResponseIsSuccessful();

        $platforms = $crawler->filter('.plat');
        self::assertGreaterThanOrEqual(3, $platforms->count(), 'Garmin, Android and the Karoo should all be listed');

        foreach ($platforms as $node) {
            $isLink = 'a' === $node->nodeName;
            $text = (string) $node->textContent;
            if (str_contains($text, 'Garmin')) {
                self::assertTrue($isLink, 'Garmin has shipped and must link');
                continue;
            }
            self::assertFalse($isLink, 'an unreleased platform must not be a link: '.trim($text));
        }
    }

    /** The homepage entry point must actually reach the page. */
    public function testTheHomepageCtaPointsAtTheScoutPage(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('#scout a[href="/scout"]')->count());
    }
}
