<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Legal;

use App\Legal\TermsVersions;
use App\Media\MediaConsent;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Translation\TranslatorBagInterface;

/**
 * Terms version 2 and the photo consent it rests on (owner 2026-10-09).
 * - The terms name no API as live or not: the developer page lists the ways
 *   to get the data, so the terms need no change when one goes live.
 * - The contributor terms link the licences page, which links every full
 *   text on GitHub, the terms clause included.
 * - The photo consent says what the terms say: a real place as it was, not
 *   generated, nothing added or removed, ordinary editing fine. A changed
 *   wording is a new consent version, and the report ground covers an
 *   altered scene too.
 */
final class TermsV2Test extends WebTestCase
{
    private const string CLAUSE = 'https://github.com/cycling-commons/cycling-commons/blob/main/licenses/COMMONS-TERMS-CLAUSE.md';

    public function testVersionTwoIsTodaysAndVersionOneKeepsItsDate(): void
    {
        self::assertSame(2, TermsVersions::CURRENT);
        self::assertSame(['number' => 2, 'date' => '2026-10-09'], array_intersect_key(TermsVersions::current(), ['number' => 1, 'date' => 1]));
        $all = TermsVersions::all();
        self::assertSame('2026-08-01', $all[1]['date']);
    }

    public function testTheTermsPointToTheDeveloperPageAndTheLicencesPage(): void
    {
        $client = static::createClient();
        $client->request('GET', '/terms');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        self::assertStringNotContainsString('Neither is live yet', $html, 'no claim about what is live');
        self::assertStringContainsString('lists the ways to get the data', $html);
        self::assertStringNotContainsString(self::CLAUSE, $html, 'the contributor terms go through the licences page');
        self::assertStringContainsString('href="/licenses#full-texts"', $html);
    }

    public function testTheLicencesPageLinksTheFullTermsClause(): void
    {
        $client = static::createClient();
        $client->request('GET', '/licenses');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        self::assertMatchesRegularExpression('/id="full-texts"[^>]*>[\s\S]*'.preg_quote(self::CLAUSE, '/').'/', $html);
    }

    public function testCodeTheInterfaceAndTranslationsAreNamedUnderTheCodeLicence(): void
    {
        $client = static::createClient();
        $client->request('GET', '/terms');
        self::assertStringContainsString('<b>code, user interface and translations</b> under', (string) $client->getResponse()->getContent());
        self::assertContains('terms.change.v2_code', TermsVersions::current()['changes']);
    }

    /** The terms never promise more than the privacy notice: backups keep deleted data for a while (owner 2026-10-09). */
    public function testClosingAnAccountNamesTheBackups(): void
    {
        $client = static::createClient();
        $client->request('GET', '/terms');
        self::assertStringContainsString('our encrypted backups keep it for at most 90 days', (string) $client->getResponse()->getContent());
        self::assertContains('terms.change.v2_backups', TermsVersions::current()['changes']);
    }

    /** Photos travel with their own licence; the line to reuse says so (owner 2026-10-09). */
    public function testTheAttributionLinesNamePhotosAndTheirLicence(): void
    {
        $client = static::createClient();
        $client->request('GET', '/developers');
        self::assertStringContainsString('Photos © their creators,<br/>under the licence named with each photo.', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Code, user interface and translations<br/>© their contributors, under AGPL-3.0-only.', (string) $client->getResponse()->getContent());
        $client->request('GET', '/licenses');
        $licences = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('under the licence named with each photo', $licences);
        self::assertStringContainsString('Code, user interface and translations © their contributors, via the Cycling Commons, under AGPL-3.0-only.', $licences);
        $client->request('GET', '/terms');
        $terms = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('The code, user interface and translations are free to use too, under AGPL-3.0-only', $terms);
        self::assertStringNotContainsString('UI translations', $terms, 'one name for the code licence bucket everywhere on the page');
    }

    public function testThePhotoConsentSaysWhatTheTermsSay(): void
    {
        self::assertSame('v6', MediaConsent::VERSION, 'a changed wording is a new consent version');
        $translator = static::getContainer()->get('translator');
        \assert($translator instanceof TranslatorBagInterface);
        $contract = $translator->getCatalogue('en')->get(MediaConsent::TEXT_KEY);
        self::assertStringContainsString('a real place as it was', $contract);
        self::assertStringContainsString('nothing was added to or removed from them', $contract);
        self::assertStringContainsString('cropping or toning', $contract);
        self::assertStringContainsString('altered', $translator->getCatalogue('en')->get('report.ground.generated'));
    }
}
