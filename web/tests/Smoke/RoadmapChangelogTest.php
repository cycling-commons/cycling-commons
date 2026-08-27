<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

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

        $keys = array_column(ReleaseNotes::ROADMAP, 'key');
        foreach (ReleaseNotes::RELEASES as $release) {
            $keys = array_merge($keys, $release['keys']);
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

    /** Every status used by an item must have a group to appear under. */
    public function testNoRoadmapItemHidesInAnUnknownStatus(): void
    {
        $unknown = array_values(array_unique(array_diff(
            array_column(ReleaseNotes::ROADMAP, 'status'),
            ReleaseNotes::STATUSES,
        )));

        self::assertSame([], $unknown, 'An item with a status not in STATUSES renders nowhere at all.');
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
}
