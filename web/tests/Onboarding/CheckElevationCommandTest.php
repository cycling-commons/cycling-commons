<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Onboarding;

use App\Elevation\ElevationEndpoints;
use App\Onboarding\Command\CheckElevationCommand;
use App\Onboarding\Countries;
use App\Onboarding\ElevationProbe;
use App\Onboarding\ElevationSample;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CheckElevationCommandTest extends KernelTestCase
{
    private function db(): Connection
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    private function seed(): void
    {
        $this->db()->executeStatement("INSERT INTO country (code, name, subtype, status) VALUES ('XA', 'Xaland', 'region', 'seeded')");
        $this->db()->executeStatement(
            "INSERT INTO region (slug, name, geom, area_km2, country_code, admin_level, created_at, updated_at)
             VALUES ('elev-xa', 'Elev', ST_Multi(ST_GeomFromText('POLYGON((10 46,11 46,11 47,10 47,10 46))', 4326)), 9000, 'XA', 4, NOW(), NOW())",
        );
    }

    /** @param list<float|null> $answers one per request, in order */
    private function tester(array $answers, string $defaultUrl = 'http://elev.test'): CommandTester
    {
        $i = 0;
        $http = new MockHttpClient(static function () use (&$i, $answers): MockResponse {
            $h = $answers[$i++ % \count($answers)];

            return new MockResponse(json_encode(['height' => [$h]], \JSON_THROW_ON_ERROR));
        });
        $db = $this->db();
        $command = new CheckElevationCommand(new Countries($db), new ElevationSample($db), new ElevationProbe($http, new ElevationEndpoints($defaultUrl)));
        $tester = new CommandTester($command);
        $tester->execute(['country' => 'XA']);

        return $tester;
    }

    public function testRealHeightsPass(): void
    {
        self::bootKernel();
        $this->seed();
        $tester = $this->tester([812.0, 1405.5, 640.0]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('http://elev.test', $tester->getDisplay());
    }

    public function testAllZerosFail(): void
    {
        self::bootKernel();
        $this->seed();
        $tester = $this->tester([0.0]);
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('25 of 25', $tester->getDisplay());
    }

    public function testNoEndpointFails(): void
    {
        self::bootKernel();
        $this->seed();
        $tester = $this->tester([500.0], '');
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('no elevation endpoint', $tester->getDisplay());
    }

    public function testTheEightyPercentLine(): void
    {
        self::assertFalse(CheckElevationCommand::failed([...array_fill(0, 20, null), ...array_fill(0, 5, 300.0)]), '80 % is not more than 80 %');
        self::assertTrue(CheckElevationCommand::failed([...array_fill(0, 21, 0.0), ...array_fill(0, 4, 300.0)]));
        self::assertTrue(CheckElevationCommand::failed([]));
    }

    public function testTheSampleIsTwentyFivePointsInsideTheRegion(): void
    {
        self::bootKernel();
        $this->seed();
        $points = (new ElevationSample($this->db()))->points('XA');
        self::assertCount(25, $points);
        foreach ($points as $p) {
            self::assertTrue($p['lat'] > 46 && $p['lat'] < 47 && $p['lon'] > 10 && $p['lon'] < 11);
        }
    }
}
