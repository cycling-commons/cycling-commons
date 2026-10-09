<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Traffic;

use App\Entity\User;
use App\Settings\SettingsRegistry;
use App\Settings\SystemSettingsWriter;
use App\Traffic\TrafficStore;
use App\Traffic\TrafficView;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * What curators see (docs/specs/traffic-measurements.md §4.5, §4.6).
 */
final class TrafficViewTest extends WebTestCase
{
    private static function line(array $over = []): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $over + [
            'way' => 4521877, 'dir' => 'f', 'label' => 'r', 'band' => 3, 'dayType' => 'workday',
            'quarter' => $now->format('Y').'-Q'.(intdiv((int) $now->format('n') - 1, 3) + 1), 'region' => null,
            'dayGroup' => 0, 'distanceM' => 1000, 'timeS' => 150, 'passes' => 4, 'nearby' => 0,
            'avgSpeedKmh' => 24.0, 'carSpeedBins' => null,
        ];
    }

    /** Five lines on five day groups, 1 km each on one road, as they leave the waiting room. */
    private function fiveRiders(): void
    {
        $store = static::getContainer()->get(TrafficStore::class);
        for ($g = 0; $g < 5; ++$g) {
            $store->addToTotal(self::line(['dayGroup' => $g]));
        }
        static::getContainer()->get(TrafficView::class)->invalidate();
    }

    private function user(KernelBrowser $client, array $roles, bool $twoFactor): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $u = (new User())->setEmail('view-'.bin2hex(random_bytes(3)).'@example.com');
        $u->setPassword('x');
        $u->setDisplayName('Viewer');
        $u->setEmailVerified(true);
        $u->setRoles($roles);
        if ($twoFactor) {
            $u->setTotpSecret('JBSWY3DPEHPK3PXP');
            $u->setTwoFaEnabled(true);
        }
        $em->persist($u);
        $em->flush();
        $client->loginUser($u);
    }

    public function testFiveRidersOnARoadAreShownUnderTheActiveScheme(): void
    {
        self::bootKernel();
        $this->fiveRiders();

        $shown = static::getContainer()->get(TrafficView::class)->shown();

        self::assertCount(1, $shown);
        self::assertSame(4521877, $shown[0]['way']);
        self::assertSame('workday', $shown[0]['group'], 'the default scheme splits workday and weekend');
        self::assertSame('busy', $shown[0]['traffic']);
    }

    public function testANewUploadDoesNotChangeTheViewUntilTheNextRecompute(): void
    {
        self::bootKernel();
        $this->fiveRiders();
        $before = static::getContainer()->get(TrafficView::class)->shown();

        static::getContainer()->get(TrafficStore::class)->addToTotal(self::line(['dayGroup' => 9, 'passes' => 40]));

        self::assertSame($before, static::getContainer()->get(TrafficView::class)->shown(),
            'no upload may be read off as the difference between two views');
    }

    public function testARoadIsShownAsABandNeverANumber(): void
    {
        self::bootKernel();
        $this->fiveRiders();

        $shown = static::getContainer()->get(TrafficView::class)->shown()[0];
        self::assertSame('busy', $shown['traffic'], '4 cars per km');
        self::assertArrayNotHasKey('carsPerKm', $shown);
    }

    public function testTheSchemeIsTheAdminSetting(): void
    {
        self::bootKernel();
        static::getContainer()->get(SystemSettingsWriter::class)->set(SettingsRegistry::TRAFFIC_GROUPING, 'all', null);
        $this->fiveRiders();

        self::assertSame('all', static::getContainer()->get(TrafficView::class)->shown()[0]['group']);
    }

    public function testTheGroupingSettingAcceptsOnlyTheKnownSchemes(): void
    {
        self::bootKernel();
        $this->expectException(\InvalidArgumentException::class);
        static::getContainer()->get(SystemSettingsWriter::class)->set(SettingsRegistry::TRAFFIC_GROUPING, 'per_minute', null);
    }

    public function testACuratorWithTwoFactorGetsThePrivateList(): void
    {
        $client = static::createClient();
        $settings = static::getContainer()->get(SystemSettingsWriter::class);
        $settings->set(SettingsRegistry::TRAFFIC_MAP_LAYER_LIVE, 1, null);
        try {
            $this->fiveRiders();
            $this->user($client, ['ROLE_CURATOR'], true);

            $client->request('GET', '/map/traffic');

            self::assertResponseIsSuccessful();
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
            $body = json_decode((string) $client->getResponse()->getContent(), true);
            self::assertSame(['workday', 'weekend'], $body['groups']);
            self::assertSame(4521877, $body['shown'][0]['way']);
        } finally {
            $settings->set(SettingsRegistry::TRAFFIC_MAP_LAYER_LIVE, 0, null);
        }
    }

    /** Switched off until it is in use (§4.6): the list does not exist while the layer is off. */
    public function testWhileTheLayerIsOffTheListIsNotFound(): void
    {
        $client = static::createClient();
        $this->fiveRiders();
        $this->user($client, ['ROLE_CURATOR'], true);

        $client->request('GET', '/map/traffic');

        self::assertResponseStatusCodeSame(404);
        self::assertArrayNotHasKey('shown', (array) json_decode((string) $client->getResponse()->getContent(), true));
    }

    public function testACuratorWithoutTwoFactorOrARiderIsRefused(): void
    {
        $client = static::createClient();
        $this->user($client, ['ROLE_CURATOR'], false);
        $client->request('GET', '/map/traffic');
        self::assertResponseStatusCodeSame(403);

        $this->user($client, [], false);
        $client->request('GET', '/map/traffic');
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheCuratorMapCarriesTheLayerAndTheRoadPieces(): void
    {
        $client = static::createClient();
        $settings = static::getContainer()->get(SystemSettingsWriter::class);
        $settings->set(SettingsRegistry::TRAFFIC_MAP_LAYER_LIVE, 1, null);
        try {
            $this->user($client, ['ROLE_CURATOR'], true);
            $client->request('GET', '/map');

            $html = (string) $client->getResponse()->getContent();
            self::assertStringContainsString('id="ovTraffic"', $html);
            self::assertStringContainsString('"roadpieces":', $html);
        } finally {
            $settings->set(SettingsRegistry::TRAFFIC_MAP_LAYER_LIVE, 0, null);
        }
    }

    /** Not in use yet (owner 2026-10-08): off by default, a curator's map shows no traffic layer. */
    public function testWhileTheSettingIsOffACuratorSeesNoLayer(): void
    {
        $client = static::createClient();
        $this->user($client, ['ROLE_CURATOR'], true);
        $client->request('GET', '/map');

        $html = (string) $client->getResponse()->getContent();
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('id="ovTraffic"', $html);
        self::assertStringNotContainsString('id="trafficCtl"', $html);
    }

    public function testARiderMapHasNeither(): void
    {
        $client = static::createClient();
        $this->user($client, [], false);
        $client->request('GET', '/map');

        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('id="ovTraffic"', $html);
        self::assertStringNotContainsString('"roadpieces":', $html);
    }
}
