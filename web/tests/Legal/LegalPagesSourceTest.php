<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Legal;

use App\Legal\TermsVersions;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * The privacy notice and the terms are one file per page per language
 * (translations/privacy.<locale>.yaml, translations/terms.<locale>.yaml),
 * outside the `messages` domain the in-site translation system reads and
 * overlays (owner 2026-10-09). A file's public history then shows every
 * change to that page in that language, line by line.
 */
final class LegalPagesSourceTest extends WebTestCase
{
    private const array LOCALES = ['en', 'fr', 'nl', 'de', 'es'];

    public function testEachPageIsItsOwnFilePerLanguageAndNotInMessages(): void
    {
        $dir = \dirname(__DIR__, 2).'/translations';
        foreach (self::LOCALES as $locale) {
            foreach (['privacy', 'terms'] as $page) {
                $file = "{$dir}/{$page}.{$locale}.yaml";
                self::assertFileExists($file);
                $tree = Yaml::parseFile($file);
                self::assertIsArray($tree);
                self::assertSame([$page], array_keys($tree), "{$page}.{$locale}.yaml holds that page only");
            }
            $messages = Yaml::parseFile("{$dir}/messages.{$locale}.yaml");
            self::assertIsArray($messages);
            self::assertArrayNotHasKey('privacy', $messages, "messages.{$locale}.yaml: the in-site translation system never sees the privacy notice");
            self::assertArrayNotHasKey('terms', $messages, "messages.{$locale}.yaml: nor the terms");
        }
    }

    /** The terms carry a version and its date, like the privacy notice (owner 2026-10-09). */
    public function testTheTermsShowTheirVersionAndEveryVersionWithWhatChanged(): void
    {
        $client = static::createClient();
        $client->request('GET', '/terms');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        $all = TermsVersions::all();
        $first = $all[\count($all) - 1];
        self::assertSame(1, $first['number']);
        self::assertSame('2026-08-01', $first['date'], 'version 1 keeps the date the terms already had');
        self::assertStringContainsString('data-terms-version="'.TermsVersions::CURRENT.'"', $html);
        self::assertStringContainsString('Version 1 · ', $html, 'every version is listed');
        self::assertStringContainsString('id="changes"', $html);
        self::assertStringNotContainsString('Last updated', $html, 'the version line replaces the bare date');
    }

    public function testEveryTermsChangeIsWrittenInEveryLanguage(): void
    {
        $translator = static::getContainer()->get('translator');
        \assert($translator instanceof TranslatorBagInterface);
        foreach (self::LOCALES as $locale) {
            $catalogue = $translator->getCatalogue($locale);
            self::assertTrue($catalogue->defines('terms.version_line', 'terms'), $locale);
            foreach (TermsVersions::all() as $v) {
                foreach ($v['changes'] as $key) {
                    self::assertTrue($catalogue->defines($key, 'terms'), $locale.': '.$key);
                }
            }
        }
    }

    public function testEachPageLinksTheHistoryOfItsOwnLanguagesFile(): void
    {
        $client = static::createClient();
        $urls = static::getContainer()->get(UrlGeneratorInterface::class);
        foreach (self::LOCALES as $locale) {
            foreach (['privacy', 'terms'] as $page) {
                $client->request('GET', $urls->generate($page, ['_locale' => $locale]));
                self::assertResponseIsSuccessful("{$page} in {$locale}");
                self::assertStringContainsString(
                    "https://github.com/cycling-commons/cycling-commons/commits/main/web/translations/{$page}.{$locale}.yaml",
                    (string) $client->getResponse()->getContent(),
                );
            }
        }
    }
}
