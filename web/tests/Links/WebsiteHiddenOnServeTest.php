<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Links;

use App\Catalog\CatalogProvider;
use App\Links\WebsiteVerdict;
use App\Links\WebsiteVerdictStore;
use App\Tests\Api\ApiSeeding;
use App\Tests\Coverage\CoverageSchema;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * What a rider sees (docs/specs/website-liveness.md section 3): a coverage
 * point's dead or parked website tag is not served, an item's dead or parked
 * `web` is served neither on the map nor through the API and stays stored,
 * and a host never checked is shown.
 */
final class WebsiteHiddenOnServeTest extends WebTestCase
{
    use ApiSeeding;
    use CoverageSchema;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        self::ensureCoverageSchema($this->db());
        $store = static::getContainer()->get(WebsiteVerdictStore::class);
        $now = new \DateTimeImmutable();
        $store->record('dead.test', 'https://dead.test/', WebsiteVerdict::failed('Could not resolve host: dead.test'), $now);
        $store->record('parked.test', 'https://parked.test/', WebsiteVerdict::of(404, '<title>ConnectYourDomain Error | Wix.com</title>'), $now);
        $store->record('camping.test', 'https://camping.test/', WebsiteVerdict::of(200, '<title>Camping</title>'), $now);
    }

    public function testACoveragePointsDeadWebsiteTagIsNotServed(): void
    {
        self::insertCoveragePoi($this->db(), [
            'ref' => 'node/77001', 'letter' => 'O', 'name' => 'Gîte', 'lat' => 50.4, 'lng' => 5.8,
            'tags' => ['tourism' => 'guest_house', 'website' => 'https://dead.test/', 'contact:website' => 'https://camping.test/', 'phone' => '+32 80 000000'],
        ]);

        $tags = (array) $this->getJson($this->client, '/map/coverage/poi/node/77001')['tags'];

        self::assertArrayNotHasKey('website', $tags);
        self::assertSame('https://camping.test/', $tags['contact:website'], 'each tag is judged by its own host');
        self::assertSame('+32 80 000000', $tags['phone']);
    }

    public function testAnItemsParkedWebsiteIsServedNowhereAndStaysStored(): void
    {
        $hidden = $this->item('B', 'Fontaine', 5.8, 50.4, 'verified', ['web' => 'https://parked.test/']);
        $shown = $this->item('B', 'Pompe', 5.81, 50.41, 'verified', ['web' => 'https://camping.test/']);
        $unseen = $this->item('B', 'Bron', 5.82, 50.42, 'verified', ['web' => 'https://never-checked.test/']);
        $catalog = static::getContainer()->get(CatalogProvider::class);

        self::assertArrayNotHasKey('web', $catalog->featureForItem($hidden)['feature']['properties'] ?? []);
        self::assertSame('https://camping.test/', $catalog->featureForItem($shown)['feature']['properties']['web'] ?? null);
        self::assertSame('https://never-checked.test/', $catalog->featureForItem($unseen)['feature']['properties']['web'] ?? null, 'a host never checked is unknown, and shown');

        self::assertArrayNotHasKey('web', $this->getJson($this->client, '/v1/items/'.$hidden)['properties']['attributes']);
        self::assertSame('https://camping.test/', $this->getJson($this->client, '/v1/items/'.$shown)['properties']['attributes']['web'] ?? null);

        self::assertSame('https://parked.test/', $this->db()->fetchOne("SELECT attributes->>'web' FROM item WHERE id = ?", [$hidden]), 'stored, so a curator still sees what was there');
    }
}
