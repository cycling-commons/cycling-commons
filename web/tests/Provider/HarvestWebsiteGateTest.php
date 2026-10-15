<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Provider;

use App\Links\WebsiteChecker;
use App\Provider\Entity\DataProvider;
use App\Provider\ProviderHarvest;
use App\Tests\Coverage\CoverageSchema;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The provider ingest's gate (docs/specs/website-liveness.md section 2): a
 * record's website is checked at intake, and a dead or parked one is left
 * out of the row and counted, never stored to be hidden later.
 */
final class HarvestWebsiteGateTest extends KernelTestCase
{
    use CoverageSchema;

    private const float LAT = 50.4;
    private const float LNG = 5.8;

    /** @var list<string> */
    private array $fetched = [];

    protected function setUp(): void
    {
        self::bootKernel();
        self::ensureCoverageSchema($this->db());
        $http = new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->fetched[] = $url;
            if (str_contains($url, 'parked')) {
                return new MockResponse('<title>ConnectYourDomain Error | Wix.com</title>', ['http_code' => 404]);
            }
            if (str_contains($url, 'dead')) {
                return new MockResponse('', ['error' => 'Could not resolve host: dead.test']);
            }

            return new MockResponse('<title>Camping</title>', ['http_code' => 200]);
        });
        static::getContainer()->set(WebsiteChecker::class, new WebsiteChecker($http, new NullLogger()));
    }

    public function testAParkedWebsiteIsLeftOutOfTheRowAndCounted(): void
    {
        $counts = $this->harvest()->apply($this->provider(), [
            $this->feature('web-gate-test:parked', 'https://parked.test/'),
            $this->feature('web-gate-test:live', 'https://camping.test/'),
        ]);

        self::assertSame(2, $counts['inserted'], 'the place is never refused, only its website');
        self::assertSame(1, $counts['websites_dropped']);
        self::assertNull($this->web('web-gate-test:parked'));
        self::assertSame('https://camping.test/', $this->web('web-gate-test:live'));
        self::assertSame('parked', $this->db()->fetchOne("SELECT verdict FROM website_check WHERE host = 'parked.test'"), 'the verdict is kept for the sweep');
    }

    public function testARerunDoesNotBringADeadWebsiteOntoTheRow(): void
    {
        $this->harvest()->apply($this->provider(), [$this->feature('web-gate-test:again', null)]);

        $counts = $this->harvest()->apply($this->provider(), [$this->feature('web-gate-test:again', 'https://dead.test/')]);

        self::assertSame(1, $counts['updated']);
        self::assertSame(1, $counts['websites_dropped']);
        self::assertNull($this->web('web-gate-test:again'));
    }

    public function testOneHostIsFetchedOnceForAWholeRun(): void
    {
        $this->harvest()->apply($this->provider(), [
            $this->feature('web-gate-test:one', 'https://camping.test/a'),
            $this->feature('web-gate-test:two', 'https://camping.test/b'),
        ]);

        self::assertCount(1, $this->fetched, 'a verdict is about a host, and two places with the same site share it');
    }

    // --- helpers ----------------------------------------------------------

    private function web(string $ref): ?string
    {
        $web = $this->db()->fetchOne("SELECT attributes->>'web' FROM item WHERE source_ref = ?", [$ref]);

        return \is_string($web) ? $web : null;
    }

    /** @return array{ref: string, letter: string, name: string, lat: float, lng: float, attributes: array<string, mixed>, country_code: string} */
    private function feature(string $ref, ?string $web): array
    {
        return [
            'ref' => $ref, 'letter' => 'O', 'name' => 'Camping', 'lat' => self::LAT, 'lng' => self::LNG,
            'attributes' => null === $web ? ['type' => 'Campsite'] : ['type' => 'Campsite', 'web' => $web],
            'country_code' => 'BE',
        ];
    }

    private function provider(): DataProvider
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $existing = $em->getRepository(DataProvider::class)->findOneBy(['key' => 'web-gate-test']);
        if (null !== $existing) {
            return $existing;
        }
        $provider = new DataProvider('web-gate-test', 'Web gate test', 'Web gate test, in full', 'https://example.test/', 'CC0 1.0', 'cc0-1.0', 10);
        $provider->setMatchRadiusM(15);
        $provider->setCountryCode('BE');
        $em->persist($provider);
        $em->flush();

        return $provider;
    }

    private function harvest(): ProviderHarvest
    {
        return static::getContainer()->get(ProviderHarvest::class);
    }

    private function db(): Connection
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }
}
