<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Tests\Smoke;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The map key: the /map-key explainer page and the Key panel on the map rail.
 *
 * The page documents every mark the map draws, plus the designed-but-unbuilt
 * marks, each of those carrying a visible "planned" tag so the page never
 * promises a mark a rider cannot find (docs/specs/map-and-search.md §4.7).
 */
final class MapKeyTest extends WebTestCase
{
    public function testMapKeyPageRenders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/map-key');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('footer.foot');
        self::assertSelectorTextContains('h1', 'Every mark on the map');

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('OpenStreetMap', $html);
        self::assertStringContainsString('drinkable', $html);
    }

    /**
     * The slug is localised too, not only the prefix, so the URL is generated
     * rather than typed (the licences-page lesson, ContentPagesTest).
     */
    public function testMapKeyPageSlugIsLocalised(): void
    {
        $client = static::createClient();
        $url = static::getContainer()->get('router')->generate('map_key', ['_locale' => 'fr']);

        self::assertSame('/fr/legende-carte', $url, 'the French slug is translated, not just prefixed');

        $client->request('GET', $url);
        self::assertResponseIsSuccessful();
    }

    public function testMapKeyPageRendersInEveryLocale(): void
    {
        $client = static::createClient();
        $router = static::getContainer()->get('router');
        foreach (['en', 'fr', 'nl', 'de', 'es'] as $locale) {
            $client->request('GET', $router->generate('map_key', ['_locale' => $locale]));
            self::assertResponseIsSuccessful(sprintf('the %s map key page must render', $locale));
        }
    }

    /**
     * Designed marks that the map does not draw yet stay on the page, but
     * every one of them wears the planned tag (owner 2026-09-01: "with a
     * small notification waiting implementation").
     */
    public function testPlannedMarksAreTaggedOnThePage(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/map-key');
        self::assertResponseIsSuccessful();

        self::assertGreaterThanOrEqual(
            3,
            $crawler->filter('.mk-tag--plan')->count(),
            'the provider tier and both proposed badges carry the planned tag',
        );
        self::assertStringContainsString(
            'not on the map yet',
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testMapRailHasKeyPanel(): void
    {
        $client = static::createClient();
        $client->request('GET', '/map');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.irail .ib[data-panel="key"]');
        self::assertSelectorExists('#p-key');
        // The panel is the quick reference; the page is the full story.
        self::assertSelectorExists('#p-key a[href$="/map-key"]');
    }

    /**
     * The panel shows live marks only, and the pending-border row belongs to
     * moderation chrome: anonymous visitors never see it (the flag comes from
     * the controller, never is_granted() — the 2FA policy applies in exactly
     * one place).
     */
    public function testKeyPanelHidesCuratorRowFromAnonymous(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/map');
        self::assertResponseIsSuccessful();

        self::assertSame(0, $crawler->filter('#p-key .mk-curator')->count());
        self::assertStringNotContainsString(
            'Waiting for a moderator',
            $crawler->filter('#p-key')->html(),
        );
    }
}
