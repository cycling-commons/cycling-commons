<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ScoutPageTest extends WebTestCase
{
    private const string GARMIN_URL = 'https://apps.garmin.com/apps/7849af70-3769-4143-bea7-8c1ea5e5f847';
    private const string KAROO_RELEASES_URL = 'https://github.com/cycling-commons/scout/releases';

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
     * shipped must not look clickable. Android is "coming soon" and the iPhone is
     * planned: rendering either as a link would be a CTA leading nowhere, the
     * fake-mechanism pattern the gate exists to catch. The Karoo beta is out for
     * testers, so its chip links the GitHub releases that hold the app and the
     * install steps.
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
            if (str_contains($text, 'Karoo')) {
                self::assertTrue($isLink, 'the Karoo beta is out for testers and must link');
                self::assertSame(self::KAROO_RELEASES_URL, $node instanceof \DOMElement ? $node->getAttribute('href') : null);
                continue;
            }
            self::assertFalse($isLink, 'an unreleased platform must not be a link: '.trim($text));
        }
    }

    /**
     * A rider back home goes from the hero straight to the ride review on the
     * map, and the page says the radar also measures traffic.
     */
    #[DataProvider('locales')]
    public function testTheHeroLinksTheRideReviewAndThePageExplainsTraffic(string $prefix): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', $prefix.'/scout');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.shero .scta a[href="/scout/review"]'));
        self::assertSelectorExists('.shero .sget + .plats');
        self::assertSelectorExists('#traffic h2');
        self::assertSelectorExists('#traffic b');
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
