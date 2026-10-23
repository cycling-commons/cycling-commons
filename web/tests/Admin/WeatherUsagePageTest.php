<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Entity\User;
use App\Weather\ForecastUsage;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The admin's Weather usage page (route-planner.md section 8): admin-only and
 * read-only; the four meters against Open-Meteo's free tier, an empty state
 * with no asks, and with asks the charts and a row per day, 7, 30 or 90 days.
 */
final class WeatherUsagePageTest extends WebTestCase
{
    /** @param list<string> $roles */
    private function createUser(string $email, array $roles = [], bool $admin2fa = false): User
    {
        $c = static::getContainer();
        $u = new User();
        $u->setEmail($email);
        $u->setDisplayName(strstr($email, '@', true) ?: $email);
        $u->setEmailVerified(true);
        $u->setEmailVerifiedAt(new \DateTimeImmutable());
        $u->setRoles($roles);
        $u->setPassword($c->get(UserPasswordHasherInterface::class)->hashPassword($u, 'password1234'));
        if ($admin2fa) {
            $u->setTotpSecret('JBSWY3DPEHPK3PXP');
            $u->setTwoFaEnabled(true);
        }
        $em = $c->get(EntityManagerInterface::class);
        $em->persist($u);
        $em->flush();

        return $u;
    }

    /** @param array<string, int> $query */
    private function url(array $query = []): string
    {
        return static::getContainer()->get('router')->generate('admin_weather_usage', $query);
    }

    public function testANonAdminIsForbidden(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('weather-curator@example.com', ['ROLE_CURATOR']));
        $client->request('GET', $this->url());

        self::assertResponseStatusCodeSame(403);
    }

    public function testWithoutAsksTheMetersStandAndThePageSaysSo(): void
    {
        $client = static::createClient();
        static::getContainer()->get(Connection::class)->executeStatement('DELETE FROM forecast_usage');
        $client->loginUser($this->createUser('weather-admin@example.com', ['ROLE_ADMIN'], admin2fa: true));
        $client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('of 10,000', $html, 'the day meter against the free tier');
        self::assertStringContainsString('of 300,000', $html);
        self::assertStringContainsString('No forecast was asked in these days.', $html);
        self::assertStringNotContainsString('wu-chart', $html);
    }

    public function testAsksShowAsChartsAndARowPerDay(): void
    {
        $client = static::createClient();
        static::getContainer()->get(Connection::class)->executeStatement('DELETE FROM forecast_usage');
        $usage = static::getContainer()->get(ForecastUsage::class);
        $usage->asked(false, 1.5);
        $usage->asked(true);
        $client->loginUser($this->createUser('weather-admin2@example.com', ['ROLE_ADMIN'], admin2fa: true));

        $crawler = $client->request('GET', $this->url(['days' => 7]));

        self::assertResponseIsSuccessful();
        self::assertCount(3, $crawler->filter('svg.wu-chart'), 'asks per day, counted per day, counted per hour');
        self::assertCount(7, $crawler->filter('.wu-table tbody tr'));
        self::assertSame(gmdate('Y-m-d'), $crawler->filter('.wu-table tbody tr time')->first()->attr('datetime'), 'today first');
        self::assertStringContainsString('1 sent to Open-Meteo, 1 from our cache', (string) $client->getResponse()->getContent());

        $crawler = $client->request('GET', $this->url(['days' => 12]));
        self::assertCount(30, $crawler->filter('.wu-table tbody tr'), 'a range we do not offer shows 30 days');
    }
}
