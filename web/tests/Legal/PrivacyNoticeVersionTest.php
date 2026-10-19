<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Legal;

use App\Entity\User;
use App\Legal\PrivacyNoticeVersions;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The privacy notice carries a version (docs/specs/privacy-notice.md): the page
 * names it and lists what changed, a signed-in rider who has not seen the
 * latest version is told so on every page until they open it, and a new
 * account starts at the version it signed up under.
 */
final class PrivacyNoticeVersionTest extends WebTestCase
{
    private function rider(KernelBrowser $client, ?int $seen): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $u = (new User())->setEmail('pv-'.bin2hex(random_bytes(3)).'@example.com');
        $u->setPassword('x');
        $u->setDisplayName('Rider');
        $u->setEmailVerified(true);
        $u->setPrivacyVersionSeen($seen);
        $em->persist($u);
        $em->flush();
        $client->loginUser($u);

        return $u;
    }

    private function seen(User $u): ?int
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->find(User::class, $u->getId())?->getPrivacyVersionSeen();
    }

    public function testThePageNamesItsVersionAndListsEveryChange(): void
    {
        $client = static::createClient();
        $page = $client->request('GET', '/privacy');

        self::assertResponseIsSuccessful();
        self::assertSame((string) PrivacyNoticeVersions::CURRENT, $page->filter('[data-privacy-version]')->attr('data-privacy-version'));
        self::assertCount(\count(PrivacyNoticeVersions::all()), $page->filter('#changes [data-version]'));
    }

    public function testARiderWhoHasNotSeenTheLatestVersionIsToldOnEveryPage(): void
    {
        $client = static::createClient();
        $this->rider($client, PrivacyNoticeVersions::CURRENT - 1);

        foreach (['/account', '/account/settings'] as $path) {
            $page = $client->request('GET', $path);
            self::assertCount(1, $page->filter('[data-privacy-changed]'), $path);
        }
    }

    public function testOpeningTheNoticeRecordsItAsSeenAndTheBarGoes(): void
    {
        $client = static::createClient();
        $u = $this->rider($client, null);

        $client->request('GET', '/privacy');
        self::assertSame(PrivacyNoticeVersions::CURRENT, $this->seen($u));

        $page = $client->request('GET', '/account');
        self::assertCount(0, $page->filter('[data-privacy-changed]'));
    }

    public function testAVisitorWhoIsNotSignedInSeesNoBar(): void
    {
        $client = static::createClient();
        $page = $client->request('GET', '/');

        self::assertCount(0, $page->filter('[data-privacy-changed]'));
    }

    public function testEveryVersionHasADateAndAtLeastOneChange(): void
    {
        $numbers = [];
        foreach (PrivacyNoticeVersions::all() as $v) {
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $v['date']);
            self::assertNotEmpty($v['changes']);
            $numbers[] = $v['number'];
        }
        self::assertSame(PrivacyNoticeVersions::CURRENT, max($numbers));
    }

    public function testEveryChangeLineIsWrittenInEveryLanguage(): void
    {
        $translator = static::getContainer()->get('translator');
        \assert($translator instanceof \Symfony\Component\Translation\TranslatorBagInterface);
        foreach (['en', 'nl', 'fr', 'de', 'es'] as $locale) {
            $catalogue = $translator->getCatalogue($locale);
            foreach (PrivacyNoticeVersions::all() as $v) {
                foreach ($v['changes'] as $key) {
                    self::assertTrue($catalogue->defines($key, 'privacy'), $locale.': '.$key);
                }
            }
        }
    }

    public function testTheBaseLocationChangeIsAnnounced(): void
    {
        $v2 = array_values(array_filter(PrivacyNoticeVersions::all(), static fn (array $v): bool => 2 === $v['number']));
        self::assertCount(1, $v2);
        self::assertContains('privacy.change.v2_base_location', $v2[0]['changes']);
    }

    /** A felt-safety mark (traffic-measurements.md section 10): what it sends, and how long it stays. */
    public function testThePageSaysWhatAFeltSafetyMarkSendsAndKeeps(): void
    {
        $client = static::createClient();
        $page = $client->request('GET', '/privacy');

        self::assertCount(2, $page->filter('[data-road-safety]'), 'what we collect, and how long we keep it');
    }

    /** Everything since the published version 2 ships as one version 3 (owner 2026-10-15; privacy-notice.md section 7a). */
    public function testEverythingSinceThePublishedVersionIsAnnouncedAsVersionThree(): void
    {
        $v3 = array_values(array_filter(PrivacyNoticeVersions::all(), static fn (array $v): bool => 3 === $v['number']));
        self::assertCount(1, $v3);
        foreach (['v3_kept_address', 'v4_roadmap', 'v5_rides', 'v6_forecast', 'v7_presets', 'v8_road_safety'] as $change) {
            self::assertContains('privacy.change.'.$change, $v3[0]['changes']);
        }
        self::assertSame(3, PrivacyNoticeVersions::CURRENT, 'nothing past version 3 until version 3 is online');
    }
}
