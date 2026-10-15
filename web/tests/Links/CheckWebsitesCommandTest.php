<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Links;

use App\Catalog\Entity\Item;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Links\WebsiteChecker;
use App\Tests\Coverage\CoverageSchema;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The sweep (docs/specs/website-liveness.md section 2): the hosts behind the
 * coverage points' website tags and the served items' `web`, never-checked
 * first, within a limit, each fetched once and its verdict kept.
 */
final class CheckWebsitesCommandTest extends KernelTestCase
{
    use CoverageSchema;

    private function db(): Connection
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    /** @var list<string> the addresses the mock engine was asked for in the current run */
    private array $fetched = [];
    private bool $mocked = false;

    /**
     * Run the sweep against a mock web where any address with "parked" in it is a parking page. The mock is installed once per test: a service already built cannot be replaced.
     *
     * @param list<string> $fetched
     */
    private function sweep(array &$fetched, int $limit = 50): CommandTester
    {
        if (!$this->mocked) {
            $http = new MockHttpClient(function (string $method, string $url): MockResponse {
                $this->fetched[] = $url;

                return str_contains($url, 'parked') ? new MockResponse('<title>ConnectYourDomain Error | Wix.com</title>', ['http_code' => 404]) : new MockResponse('<title>ok</title>', ['http_code' => 200]);
            });
            static::getContainer()->set(WebsiteChecker::class, new WebsiteChecker($http, new NullLogger()));
            $this->mocked = true;
        }
        $this->fetched = [];
        $tester = new CommandTester((new Application(static::$kernel))->find('app:websites:check'));
        $tester->execute(['--limit' => $limit]);
        $fetched = $this->fetched;

        return $tester;
    }

    private function seedPoint(string $ref, string $website): void
    {
        $this->db()->executeStatement(
            "INSERT INTO coverage_poi (ref, letter, name, geom, tags, country_code)
             VALUES (:ref, 'O', 'Gîte', ST_SetSRID(ST_MakePoint(5.9, 50.4), 4326), :tags::jsonb, 'BE')",
            ['ref' => $ref, 'tags' => json_encode(['website' => $website, 'tourism' => 'guest_house'], \JSON_THROW_ON_ERROR)],
        );
    }

    private function seedItem(string $web): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $item = (new Item())->setLetter('O')->setName('Hotel')
            ->setGeom(json_encode(['type' => 'Point', 'coordinates' => [5.9, 50.4]], \JSON_THROW_ON_ERROR))
            ->setCountryCode('BE')->setState(ItemState::Verified)->setSource(ItemSource::Manual)
            ->setSourceRef('manual:web-'.md5($web))->setAttributes(['web' => $web]);
        $em->persist($item);
        $em->flush();
    }

    public function testTheSweepChecksHostsFromPointsAndItemsOnceEachAndKeepsTheVerdicts(): void
    {
        self::bootKernel();
        self::ensureCoverageSchema($this->db());
        $this->seedPoint('node/1', 'https://www.chevrouheid-parked.test/');
        $this->seedPoint('node/2', 'https://shared.test/a');
        $this->seedPoint('node/3', 'https://shared.test/b');
        $this->seedItem('https://hotel.test/');
        $fetched = [];

        $tester = $this->sweep($fetched);

        self::assertSame(0, $tester->getStatusCode());
        sort($fetched);
        self::assertSame(['https://hotel.test/', 'https://shared.test/a', 'https://www.chevrouheid-parked.test/'], $fetched, 'each host once, the first address seen');
        $rows = $this->db()->fetchAllKeyValue("SELECT host, verdict FROM website_check WHERE host IN ('www.chevrouheid-parked.test', 'shared.test', 'hotel.test')");
        self::assertSame(['hotel.test' => 'live', 'shared.test' => 'live', 'www.chevrouheid-parked.test' => 'parked'], $rows);
        self::assertStringContainsString('3 hosts', $tester->getDisplay());
    }

    public function testAHostCheckedWithinItsPeriodIsNotFetchedAgainAndTheLimitHolds(): void
    {
        self::bootKernel();
        self::ensureCoverageSchema($this->db());
        $this->seedPoint('node/11', 'https://one.test/');
        $this->seedPoint('node/12', 'https://two.test/');
        $fetched = [];
        $this->sweep($fetched, 1);
        self::assertCount(1, $fetched, 'the limit bounds a run');

        $again = [];
        $this->sweep($again, 50);
        self::assertCount(1, $again, 'only the host not yet checked is fetched');
        self::assertNotSame($fetched, $again);
    }
}
