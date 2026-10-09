<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Legal;

use App\Tests\Translation\FindsOrCreatesTranslationEntry;
use App\Translation\Entity\TranslationOverlay;
use App\Translation\OverlayCatalogue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The licences page text is one file per language
 * (translations/licenses.<locale>.yaml, domain `licenses`), outside the
 * `messages` domain the in-site translation system reads and overlays (owner
 * 2026-10-09), like the privacy notice and the terms. The terms include the
 * page by reference (TermsIncludedTexts), so its words change in git only.
 *
 * @see docs/specs/translations.md §6.2
 */
final class LicensesPageSourceTest extends WebTestCase
{
    use FindsOrCreatesTranslationEntry;

    private const array LOCALES = ['en', 'fr', 'nl', 'de', 'es'];

    public function testTheTextIsItsOwnFilePerLanguageAndNotInMessages(): void
    {
        foreach (self::LOCALES as $locale) {
            $tree = Yaml::parseFile(self::dir()."/licenses.{$locale}.yaml");
            self::assertIsArray($tree);
            self::assertSame(['licenses'], array_keys($tree), "licenses.{$locale}.yaml holds that page only");

            $messages = Yaml::parseFile(self::dir()."/messages.{$locale}.yaml");
            self::assertIsArray($messages);
            self::assertArrayNotHasKey('licenses', $messages, "messages.{$locale}.yaml: the in-site translation system never sees the licences page");
        }
    }

    /** Every plain value of the language's file is on the page in that language. */
    public function testThePageShowsItsLanguagesFileInEveryLanguage(): void
    {
        $client = static::createClient();
        $urls = static::getContainer()->get(UrlGeneratorInterface::class);
        foreach (self::LOCALES as $locale) {
            $client->request('GET', $urls->generate('licenses', ['_locale' => $locale]));
            self::assertResponseIsSuccessful("licences page in {$locale}");
            $html = (string) $client->getResponse()->getContent();
            self::assertStringContainsString('<html lang="'.$locale.'"', $html);

            $tree = Yaml::parseFile(self::dir()."/licenses.{$locale}.yaml");
            self::assertIsArray($tree);
            self::assertIsArray($tree['licenses']);
            $checked = 0;
            foreach ($tree['licenses'] as $key => $value) {
                self::assertIsString($value, "licenses.{$key}");
                // A value with markup or a placeholder goes through |rich or a parameter: its plain siblings carry the proof.
                if (str_contains($value, '<') || str_contains($value, '%')) {
                    continue;
                }
                // The {% trans %} tag prints the value as it is; the |trans filter escapes it.
                self::assertTrue(
                    str_contains($html, $value) || str_contains($html, htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')),
                    "{$locale}: licenses.{$key} is on the page",
                );
                ++$checked;
            }
            self::assertGreaterThan(60, $checked, "{$locale}: nearly every value is checked");
        }
    }

    /** An approved overlay on a licences key, left over or forged, never reaches the page. */
    public function testAnOverlayOnALicencesKeyDoesNotChangeThePage(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $entry = $this->findOrCreateEntry($em, 'licenses.title', 'The short version.');
        $em->persist(new TranslationOverlay($entry, 'fr', 'Titre réécrit hors de git', null, null, 1));
        $em->flush();
        static::getContainer()->get(OverlayCatalogue::class)->invalidate('fr');

        $translator = static::getContainer()->get(TranslatorInterface::class);
        self::assertSame('La version courte.', $translator->trans('licenses.title', [], 'licenses', 'fr'));

        $client->request('GET', '/fr/licences');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('Titre réécrit hors de git', $html);
        self::assertStringContainsString('La version courte.', $html);

        static::getContainer()->get(OverlayCatalogue::class)->invalidate('fr');
    }

    private static function dir(): string
    {
        return \dirname(__DIR__, 2).'/translations';
    }
}
