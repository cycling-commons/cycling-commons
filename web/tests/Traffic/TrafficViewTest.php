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
            'way' => 4521877, 'dir' => 'f', 'label' => 'r', 'slot' => 73, 'dayType' => 'workday',
            'season' => 'autumn', 'quarter' => $now->format('Y').'-Q'.(intdiv((int) $now->format('n') - 1, 3) + 1),
            'day' => intdiv($now->getTimestamp(), 86400), 'distanceM' => 1000, 'timeS' => 150, 'passes' => 4,
            'avgSpeedKmh' => 24.0, 'carSpeedBins' => null,
        ];
    }

    /** Five riders, five days, 1 km each on one road. */
    private function fiveRiders(): void
    {
        $store = static::getContainer()->get(TrafficStore::class);
        for ($r = 1; $r <= 5; ++$r) {
            $line = self::line(['day' => self::line()['day'] - $r]);
            $store->addToCell($line);
            $store->addToRider(1000 + $r, $line);
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
        self::assertSame(4.0, $shown[0]['carsPerKm']);
    }

    public function testANewUploadDoesNotChangeTheViewUntilTheNextRecompute(): void
    {
        self::bootKernel();
        $this->fiveRiders();
        $before = static::getContainer()->get(TrafficView::class)->shown();

        $line = self::line(['day' => self::line()['day'] - 9, 'passes' => 40]);
        static::getContainer()->get(TrafficStore::class)->addToCell($line);
        static::getContainer()->get(TrafficStore::class)->addToRider(1009, $line);

        self::assertSame($before, static::getContainer()->get(TrafficView::class)->shown(),
            'no upload may be read off as the difference between two views');
    }

    public function testCarsPerKmIsShownToOneDecimal(): void
    {
        self::bootKernel();
        $this->fiveRiders();
        $line = self::line(['day' => self::line()['day'] - 7, 'distanceM' => 3000, 'passes' => 1]);
        static::getContainer()->get(TrafficStore::class)->addToCell($line);
        static::getContainer()->get(TrafficStore::class)->addToRider(1007, $line);
        static::getContainer()->get(TrafficView::class)->invalidate();

        $value = static::getContainer()->get(TrafficView::class)->shown()[0]['carsPerKm'];
        self::assertSame(round($value, 1), $value);
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
        $this->fiveRiders();
        $this->user($client, ['ROLE_CURATOR'], true);

        $client->request('GET', '/map/traffic');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(['workday', 'weekend'], $body['groups']);
        self::assertSame(4521877, $body['shown'][0]['way']);
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
        $this->user($client, ['ROLE_CURATOR'], true);
        $client->request('GET', '/map');

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('id="ovTraffic"', $html);
        self::assertStringContainsString('"roadpieces":', $html);
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
