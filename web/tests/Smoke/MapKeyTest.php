<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Tests\Smoke;

use App\Catalog\BasemapIcons;
use App\Catalog\KindIcons;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

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
     * Nothing on the page is planned any more: the provider tier went live
     * with the marker grammar (data-provider-hierarchy.md §6.7), and the two
     * state badges before it. A planned tag would promise a mark a rider
     * cannot find.
     */
    public function testNoMarkOnThePageIsPlanned(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/map-key');
        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('.mk-tag--plan')->count(), 'every mark on the key is on the map');
        self::assertStringNotContainsString('not on the map yet', (string) $client->getResponse()->getContent());
        foreach (['Out of order', 'Not always reachable'] as $live) {
            $row = $crawler->filter('.mk-row')->reduce(static fn (Crawler $n): bool => str_contains($n->text(), $live));
            self::assertSame(1, $row->count(), "$live has a row");
            self::assertSame(1, $row->filter('.cc-st')->count(), "$live shows its badge");
        }
    }

    /**
     * The key draws the grammar the map draws (data-provider-hierarchy.md
     * §6.7): three borders for custody, one badge for evidence, from the one
     * shared pin stylesheet. The paper dot is gone from the markers, so a key
     * that still named it would describe a map that does not exist.
     */
    public function testTheKeyDrawsTheGrammarAndNeverTheRemovedPaperDot(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/map-key');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        self::assertStringNotContainsStringIgnoringCase('paper dot', $crawler->text());
        self::assertStringContainsString('styles/pins', $html, 'the key links the shared pin stylesheet');
        self::assertSame(0, $crawler->filter('.mk-pin')->count(), 'no copied pin class remains');
        // Every cell of the grid is on the key: each border with and without the badge.
        $tiers = $crawler->filter('.mk-rows')->first();
        self::assertSame(1, $tiers->filter('.cc-pin.disc.q')->count(), 'baseline: small disc, nobody stood there');
        self::assertSame(1, $tiers->filter('.cc-pin.disc:not(.q)')->count(), 'baseline: small disc, a dated witness on record');
        self::assertSame(1, $tiers->filter('.cc-pin.dashed.q')->count(), 'kept by somebody else: dashed, nobody stood there');
        self::assertSame(1, $tiers->filter('.cc-pin.dashed:not(.q)')->count(), 'kept by somebody else: dashed, a dated witness on record');
        self::assertSame(1, $tiers->filter('.cc-pin:not(.q):not(.disc):not(.dashed)')->count(), 'ours, verified');
        self::assertSame(1, $tiers->filter('.cc-pin.q:not(.disc):not(.dashed)')->count(), 'ours with the badge');
        self::assertSame(1, $tiers->filter('.cc-q')->count(), 'the badge row shows the badge itself, not a pin wearing it');
        self::assertSame(4, $tiers->filter('.mk-row')->count(), 'four rows: three borders and the one badge');
        self::assertSame(0, $crawler->filter('.cc-pin.cur, .cc-pin.community, .cc-pin.provider')->count(), 'the old one-class tiers are gone');
    }

    /**
     * The basemap furniture rows are generated from BasemapIcons, the same
     * registry the map mints from when the basemap sprite lacks the class
     * (map-and-search.md §4.7), on the page and in the rail panel.
     */
    public function testBasemapFurnitureIsGeneratedFromTheRegistry(): void
    {
        $expected = array_keys(BasemapIcons::set());
        sort($expected);
        $client = static::createClient();
        $crawler = $client->request('GET', '/map-key');
        self::assertResponseIsSuccessful();
        $page = $crawler->filter('.mk-basemap')->each(static fn (Crawler $n): string => (string) $n->attr('data-basemap'));
        sort($page);
        self::assertSame($expected, $page);
        self::assertSame(\count($expected), $crawler->filter('.mk-basemap svg.cc-basemap')->count(), 'every row draws its icon');

        $crawler = $client->request('GET', '/map');
        self::assertResponseIsSuccessful();
        $panel = $crawler->filter('#p-key .mk-basemap')->each(static fn (Crawler $n): string => (string) $n->attr('data-basemap'));
        sort($panel);
        self::assertSame($expected, $panel);
        self::assertStringContainsString('window.CC_BASEMAP_ICONS', (string) $client->getResponse()->getContent(), 'the map mints from the same registry');
    }

    /**
     * The kinds section is GENERATED from the kind registry (the same
     * definitions the map mints its tile icons from), so every registry kind
     * has a row and no row exists without one.
     */
    public function testKindsAreGeneratedFromTheRegistry(): void
    {
        $expected = [];
        foreach (KindIcons::set() as $letter => $kinds) {
            foreach (array_keys($kinds) as $kind) {
                $expected[] = "$letter:$kind";
            }
        }
        sort($expected);

        $client = static::createClient();
        $crawler = $client->request('GET', '/map-key');
        self::assertResponseIsSuccessful();
        $page = $crawler->filter('.mk-kind')->each(static fn (Crawler $n): string => (string) $n->attr('data-kind'));
        sort($page);
        self::assertSame($expected, $page, 'the page lists exactly the registry kinds');
        self::assertSame(\count($expected), $crawler->filter('.mk-kind svg.cc-kind, .mk-kind .mk-disc')->count(), 'every kind row draws its glyph');

        // The rail panel too, from the same registry.
        $crawler = $client->request('GET', '/map');
        self::assertResponseIsSuccessful();
        $panel = $crawler->filter('#p-key .mk-kind')->each(static fn (Crawler $n): string => (string) $n->attr('data-kind'));
        sort($panel);
        self::assertSame($expected, $panel, 'the Key panel lists exactly the registry kinds');
        self::assertSame(1, $crawler->filter('#p-key .mk-state-warn .cc-st.warn')->count());
        self::assertSame(1, $crawler->filter('#p-key .mk-state-hours .cc-st.hours')->count());
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
