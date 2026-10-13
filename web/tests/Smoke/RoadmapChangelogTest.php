<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Tests\Smoke;

use App\Content\ReleaseNotes;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * `/roadmap`, `/changelog` and the Atom feed.
 *
 * All three read one list (`App\Content\ReleaseNotes`), so the risk is not that
 * a page breaks: it is that somebody adds an entry and forgets a catalogue key,
 * which renders the key itself and looks broken in four languages nobody on the
 * team reads. That is what most of this file checks.
 *
 * @see docs/specs/roadmap-and-changelog.md
 */
final class RoadmapChangelogTest extends WebTestCase
{
    public function testBothPagesResolveInEveryLocale(): void
    {
        $client = static::createClient();
        $router = static::getContainer()->get('router');

        foreach (['en', 'fr', 'nl', 'de', 'es'] as $locale) {
            foreach (['roadmap', 'changelog'] as $route) {
                $path = $router->generate($route, ['_locale' => $locale]);
                $client->request('GET', $path);
                self::assertResponseIsSuccessful(sprintf('%s should render', $path));
            }
        }
    }

    /**
     * An entry whose key is missing renders as the key. Twig's translator
     * returns the id unchanged, so the page still answers 200 and the failure
     * is invisible to a smoke test that only checks the status code.
     */
    public function testEveryRoadmapAndReleaseKeyIsTranslatedInEveryLocale(): void
    {
        self::bootKernel();
        $translator = static::getContainer()->get('translator');

        $keys = [];
        foreach (ReleaseNotes::releases() as $release) {
            foreach ($release['sections'] as $notes) {
                $keys = array_merge($keys, $notes);
            }
        }
        foreach (ReleaseNotes::SECTIONS as $section) {
            $keys[] = 'changelog.section_'.$section;
        }
        foreach (ReleaseNotes::STATUSES as $status) {
            $keys[] = 'roadmap.group_'.$status;
            $keys[] = 'roadmap.group_'.$status.'_sub';
        }

        self::assertNotEmpty($keys);

        $missing = [];
        foreach (['en', 'fr', 'nl', 'de', 'es'] as $locale) {
            foreach ($keys as $key) {
                if ($key === $translator->trans($key, [], null, $locale)) {
                    $missing[] = $locale.': '.$key;
                }
            }
        }

        self::assertSame([], $missing, "Untranslated roadmap/changelog keys:\n  ".implode("\n  ", $missing));
    }

    /**
     * The roadmap's items live in the database now (§8). Every live item
     * sits in a group the page shows and has at least one text, which every
     * language without its own falls back to.
     */
    public function testEveryLiveRoadmapItemShowsSomewhereInEveryLanguage(): void
    {
        self::bootKernel();
        $db = static::getContainer()->get(\Doctrine\DBAL\Connection::class);

        $unknown = $db->fetchFirstColumn(
            "SELECT id FROM roadmap_item WHERE state = 'live' AND status NOT IN (:s)",
            ['s' => ReleaseNotes::STATUSES],
            ['s' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
        self::assertSame([], $unknown, 'An item with a status not in STATUSES renders nowhere at all.');

        $noText = $db->fetchFirstColumn(
            "SELECT i.id FROM roadmap_item i WHERE i.state = 'live'
               AND NOT EXISTS (SELECT 1 FROM roadmap_item_text t WHERE t.item_id = i.id)",
        );
        self::assertSame([], $noText, 'A live item without any text renders as an empty row.');
    }

    /**
     * Every note carries its kind (new, improved, fixed), the label beside it
     * on the page and in the feed; no label names a note the release does not
     * have, and every label reads in every locale.
     */
    public function testEveryNoteHasAKnownKind(): void
    {
        self::bootKernel();
        $translator = static::getContainer()->get('translator');
        foreach (ReleaseNotes::releases() as $release) {
            $notes = array_merge(...array_values($release['sections']));
            self::assertSame([], array_values(array_diff($notes, array_keys($release['kinds']))), $release['version'].': notes without a kind');
            self::assertSame([], array_values(array_diff(array_keys($release['kinds']), $notes)), $release['version'].': kinds for notes it does not have');
            self::assertSame([], array_values(array_diff($release['kinds'], ReleaseNotes::KINDS)), $release['version'].': unknown kinds');
        }
        foreach (ReleaseNotes::KINDS as $kind) {
            foreach (['en', 'fr', 'nl', 'de', 'es'] as $locale) {
                self::assertNotSame('changelog.kind_'.$kind, $translator->trans('changelog.kind_'.$kind, [], null, $locale), $locale.': changelog.kind_'.$kind);
            }
        }
    }

    /**
     * A note under a section name not in SECTIONS is never shown: the page
     * lists the sections it knows, in their order.
     */
    public function testNoReleaseNoteHidesInAnUnknownSection(): void
    {
        $unknown = [];
        foreach (ReleaseNotes::RELEASES as $release) {
            $unknown = array_merge($unknown, array_diff(array_keys($release['sections'] ?? []), ReleaseNotes::SECTIONS));
        }

        self::assertSame([], array_values(array_unique($unknown)), 'A section not in SECTIONS renders nowhere at all.');
    }

    /** From 0.9.4-beta a release's notes sit under their section headings (owner 2026-10-04). */
    public function testASectionedReleaseShowsItsHeadings(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/whats-new');

        self::assertResponseIsSuccessful();
        $headings = $crawler->filter('[id="v0.9.4-beta"] .csec')->each(static fn ($h): string => trim($h->text()));
        self::assertSame(['Public site', 'Rider account', 'Curator account'], $headings);
        self::assertCount(0, $crawler->filter('[id="v0.9.3-beta"] .csec'), 'an older release keeps its flat list');
    }

    /**
     * A feed reader is stricter than a browser: a stray byte before the XML
     * declaration, or an unescaped `&` from a translation, and the whole feed
     * is rejected rather than degraded.
     */
    public function testTheFeedIsWellFormedAtomAndCacheable(): void
    {
        $client = static::createClient();
        $client->request('GET', '/changelog.atom');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/atom+xml; charset=UTF-8');

        $body = (string) $client->getResponse()->getContent();
        self::assertStringStartsWith('<?xml', $body, 'nothing may precede the XML declaration');

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_use_internal_errors($previous);
        self::assertNotFalse($xml, 'the feed must parse');

        $entries = $xml->children('http://www.w3.org/2005/Atom')->entry;
        self::assertCount(\count(ReleaseNotes::RELEASES), $entries);

        // Polled, identical for everyone, and holding nothing personal.
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('public'));
    }

    /**
     * The version on this page and the one in the footer come from different
     * places on purpose: the page from `ReleaseNotes`, the footer from
     * `git describe`. They agree only if the release is tagged `v<version>`,
     * which is the deploy step this asserts the shape of.
     */
    public function testReleaseVersionsLookLikeGitTags(): void
    {
        foreach (ReleaseNotes::RELEASES as $release) {
            self::assertMatchesRegularExpression(
                '/^\d+\.\d+\.\d+(-[a-z0-9.]+)?$/',
                $release['version'],
                'the version is the git tag without its leading `v`',
            );
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $release['date']);
        }
    }

    /**
     * The English page moved from /changelog to /whats-new on 2026-09-29. The
     * old address is in bug reports, search results and bookmarks, so it
     * answers for good, query string kept; the feed keeps its address.
     */
    public function testTheOldEnglishAddressMovesForGood(): void
    {
        $client = static::createClient();
        $client->request('GET', '/changelog?utm_source=mail');
        self::assertResponseRedirects('/whats-new?utm_source=mail', 301);

        $client->request('GET', '/whats-new');
        self::assertResponseIsSuccessful();
        $client->request('GET', '/changelog.atom');
        self::assertResponseIsSuccessful('the feed keeps its address');
    }

    /** A note's own markup renders as markup, labelled or not, never as escaped text. */
    public function testNotesRenderTheirBoldLeadAsMarkup(): void
    {
        $client = static::createClient();
        $client->request('GET', '/whats-new');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('&lt;b&gt;', $html);
        self::assertStringNotContainsString('&amp;#039;', $html);
    }
}
