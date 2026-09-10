<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Town;

use App\Media\Commons\CommonsApi;
use App\Media\Commons\WikidataImageRepository;
use App\Media\Message\ResolveWikidataImage;
use App\Town\Message\ResolveTownSummary;
use App\Town\MessageHandler\ResolveTownSummaryHandler;
use App\Town\OsmElementApi;
use App\Town\TownSummaryRepository;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The town card's four hops, each answered by a canned Wikimedia or
 * OpenStreetMap reply (docs/specs/map-and-search.md §6.5). No page and no
 * races are answers, kept; only a source that does not reply lets go.
 */
final class ResolveTownSummaryHandlerTest extends KernelTestCase
{
    private TownSummaryRepository $towns;
    private WikidataImageRepository $images;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        /** @var TownSummaryRepository $towns */
        $towns = $c->get(TownSummaryRepository::class);
        $this->towns = $towns;
        /** @var WikidataImageRepository $images */
        $images = $c->get(WikidataImageRepository::class);
        $this->images = $images;
        /** @var Connection $db */
        $db = $c->get('doctrine.dbal.default_connection');
        $db->executeStatement("DELETE FROM town_summary WHERE osm_ref LIKE 'node/99999%'");
        $db->executeStatement("DELETE FROM wikidata_image WHERE qid LIKE 'Q9999998%'");
    }

    public function testATownWithAPageGetsItsParagraphItsRacesAndItsPhotoQueued(): void
    {
        $sent = [];
        $this->handle('node/999990001', 'nl', $this->antwerp(), $sent);

        $row = $this->towns->find('node/999990001', 'nl');
        self::assertNotNull($row);
        self::assertTrue($row['answered']);
        self::assertSame('Q99999801', $row['qid']);
        self::assertSame('Antwerpen', $row['title']);
        self::assertSame('nl', $row['page_lang'], 'the reader language wins when that Wikipedia has the page');
        self::assertSame('https://nl.wikipedia.org/wiki/Antwerpen_(stad)', $row['page_url']);
        self::assertStringStartsWith('Antwerpen is een stad', (string) $row['extract']);

        self::assertSame(['year' => 1200, 'precision' => 7], $row['facts']['founded'] ?? null, 'founded, with Wikidata\'s own precision (7 = century)');
        self::assertSame(['n' => 565039, 'year' => 2024], $row['facts']['population'] ?? null, 'the newest dated count: not the first, not the undated one, not the preferred 1971 one');

        self::assertCount(4, $row['cycling']);
        self::assertSame('UCI Road World Championships men\'s road race', $row['cycling'][2]['label'], 'a list-article label gives way to the English name');
        self::assertSame('Ronde van Frankrijk', $row['cycling'][3]['label']);
        self::assertSame(['stage-start'], $row['cycling'][3]['rels'], 'a stage of a bigger race says so');
        self::assertSame('Ronde van Vlaanderen', $row['cycling'][0]['label']);
        self::assertSame(['start'], $row['cycling'][0]['rels']);
        self::assertSame(8, $row['cycling'][0]['n']);
        self::assertSame(2026, $row['cycling'][0]['last']);
        self::assertSame('https://nl.wikipedia.org/wiki/Ronde_van_Vlaanderen', $row['cycling'][0]['url']);
        self::assertSame(['start', 'finish'], $row['cycling'][1]['rels'], 'start and finish on one race merge into one line');
        self::assertNull($row['cycling'][1]['url'], 'no Wikipedia page in either language: the name still shows, unlinked');

        self::assertCount(1, $sent);
        self::assertInstanceOf(ResolveWikidataImage::class, $sent[0]);
        self::assertSame('Q99999801', $sent[0]->qid, 'the photo takes the P18 path every scenic POI takes');
        self::assertNotNull($this->images->find('Q99999801'), 'and the P18 row is claimed, so the photo endpoint does not claim it twice');
    }

    public function testEnglishIsTheFallbackWhenTheReaderWikipediaHasNoPage(): void
    {
        $this->handle('node/999990002', 'es', $this->antwerp());

        $row = $this->towns->find('node/999990002', 'es');
        self::assertNotNull($row);
        self::assertSame('en', $row['page_lang']);
        self::assertSame('Antwerp', $row['title']);
        self::assertSame('https://en.wikipedia.org/wiki/Antwerp', $row['page_url']);
    }

    public function testAnElementWithoutAWikidataTagIsARememberedAnswer(): void
    {
        $sent = [];
        $this->handle('node/999990003', 'en', $this->routes(['osm' => ['elements' => [['tags' => ['place' => 'village', 'name' => 'Nowhere']]]]]), $sent);

        $row = $this->towns->find('node/999990003', 'en');
        self::assertNotNull($row);
        self::assertTrue($row['answered'], 'we asked');
        self::assertNull($row['title'], 'and there is nothing');
        self::assertSame([], $row['cycling']);
        self::assertSame([], $row['facts']);
        self::assertCount(0, $sent);
        self::assertFalse($this->towns->claim('node/999990003', 'en'), 'so nobody asks twice');
    }

    public function testOpenStreetMapBeingDownLetsGoOfTheClaim(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 503]));
        $this->handle('node/999990004', 'en', $client);

        self::assertNull($this->towns->find('node/999990004', 'en'), 'an unanswered claim is dropped, so a later visit asks again');
    }

    public function testTheQueryServiceFailingCostsTheRacesNotTheText(): void
    {
        $replies = $this->antwerpReplies();
        $replies['sparql'] = new MockResponse('', ['http_code' => 504]);
        $this->handle('node/999990005', 'nl', $this->routes($replies));

        $row = $this->towns->find('node/999990005', 'nl');
        self::assertNotNull($row);
        self::assertTrue($row['answered']);
        self::assertSame('Antwerpen', $row['title']);
        self::assertSame([], $row['cycling']);
    }

    /** @param list<object> $sent */
    private function handle(string $ref, string $lang, MockHttpClient $client, array &$sent = []): void
    {
        $this->towns->claim($ref, $lang);

        $bus = new class($sent) implements MessageBusInterface {
            /** @param list<object> $sent */
            public function __construct(private array &$sent)
            {
            }

            #[\Override]
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->sent[] = $message;

                return new Envelope($message);
            }
        };

        $handler = new ResolveTownSummaryHandler(
            new OsmElementApi($client, 'CyclingCommons-test/1.0'),
            new CommonsApi($client, 'CyclingCommons-test/1.0'),
            $this->towns,
            $this->images,
            $bus,
            new NullLogger(),
        );
        $handler(new ResolveTownSummary($ref, $lang, 'EU'));
    }

    /** A client that answers each source by its host, whatever order the handler asks in. */
    private function antwerp(): MockHttpClient
    {
        return $this->routes($this->antwerpReplies());
    }

    /** @return array<string, mixed> */
    private function antwerpReplies(): array
    {
        return [
            'osm' => ['elements' => [['tags' => ['place' => 'city', 'name' => 'Antwerpen', 'wikidata' => 'Q99999801']]]],
            'entities' => ['entities' => [
                'Q99999801' => ['labels' => ['nl' => ['value' => 'Antwerpen'], 'en' => ['value' => 'Antwerp']],
                    'sitelinks' => ['nlwiki' => ['title' => 'Antwerpen (stad)'], 'enwiki' => ['title' => 'Antwerp']]],
                'Q99999811' => ['labels' => ['nl' => ['value' => 'Ronde van Vlaanderen'], 'en' => ['value' => 'Tour of Flanders']],
                    'sitelinks' => ['nlwiki' => ['title' => 'Ronde van Vlaanderen'], 'enwiki' => ['title' => 'Tour of Flanders']]],
                'Q99999812' => ['labels' => ['en' => ['value' => 'Antwerp Port Epic']], 'sitelinks' => []],
                'Q99999813' => ['labels' => ['nl' => ['value' => 'Lijst van wereldkampioenen wegrit elite mannen'], 'en' => ['value' => 'UCI Road World Championships men\'s road race']],
                    'sitelinks' => ['nlwiki' => ['title' => 'Lijst van wereldkampioenen wegrit elite mannen']]],
                'Q99999814' => ['labels' => ['nl' => ['value' => 'Ronde van Frankrijk'], 'en' => ['value' => 'Tour de France']], 'sitelinks' => ['nlwiki' => ['title' => 'Ronde van Frankrijk']]],
            ]],
            'founded' => ['claims' => ['P571' => [['rank' => 'normal', 'mainsnak' => ['datavalue' => ['value' => ['time' => '+1200-00-00T00:00:00Z', 'precision' => 7]]]]]]],
            'population' => ['claims' => ['P1082' => [
                ['rank' => 'normal', 'mainsnak' => ['datavalue' => ['value' => ['amount' => '+520504']]], 'qualifiers' => ['P585' => [['datavalue' => ['value' => ['time' => '+2017-00-00T00:00:00Z']]]]]],
                ['rank' => 'normal', 'mainsnak' => ['datavalue' => ['value' => ['amount' => '+565039']]], 'qualifiers' => ['P585' => [['datavalue' => ['value' => ['time' => '+2024-01-01T00:00:00Z']]]]]],
                ['rank' => 'normal', 'mainsnak' => ['datavalue' => ['value' => ['amount' => '+500000']]]],
                ['rank' => 'preferred', 'mainsnak' => ['datavalue' => ['value' => ['amount' => '+999999']]], 'qualifiers' => ['P585' => [['datavalue' => ['value' => ['time' => '+1971-01-01T00:00:00Z']]]]]],
            ]]],
            'summary_nl' => ['title' => 'Antwerpen', 'extract' => 'Antwerpen is een stad in België.', 'content_urls' => ['desktop' => ['page' => 'https://nl.wikipedia.org/wiki/Antwerpen_(stad)']]],
            'summary_en' => ['title' => 'Antwerp', 'extract' => 'Antwerp is a city in Belgium.', 'content_urls' => ['desktop' => ['page' => 'https://en.wikipedia.org/wiki/Antwerp']]],
            'sparql' => ['results' => ['bindings' => [
                ['grp' => ['value' => 'http://www.wikidata.org/entity/Q99999811'], 'rel' => ['value' => 'http://www.wikidata.org/prop/direct/P1427'], 'stage' => ['value' => 'false'], 'n' => ['value' => '8'], 'last' => ['value' => '2026-04-05T00:00:00Z']],
                ['grp' => ['value' => 'http://www.wikidata.org/entity/Q99999812'], 'rel' => ['value' => 'http://www.wikidata.org/prop/direct/P1427'], 'stage' => ['value' => 'false'], 'n' => ['value' => '5'], 'last' => ['value' => '2025-09-07T00:00:00Z']],
                ['grp' => ['value' => 'http://www.wikidata.org/entity/Q99999812'], 'rel' => ['value' => 'http://www.wikidata.org/prop/direct/P1444'], 'stage' => ['value' => 'false'], 'n' => ['value' => '5'], 'last' => ['value' => '2025-09-07T00:00:00Z']],
                ['grp' => ['value' => 'http://www.wikidata.org/entity/Q99999813'], 'rel' => ['value' => 'http://www.wikidata.org/prop/direct/P1427'], 'stage' => ['value' => 'false'], 'n' => ['value' => '1'], 'last' => ['value' => '2021-09-26T00:00:00Z']],
                ['grp' => ['value' => 'http://www.wikidata.org/entity/Q99999814'], 'rel' => ['value' => 'http://www.wikidata.org/prop/direct/P1427'], 'stage' => ['value' => 'true'], 'n' => ['value' => '3'], 'last' => ['value' => '2015-07-06T00:00:00Z']],
            ]]],
        ];
    }

    /** @param array<string, mixed> $replies */
    private function routes(array $replies): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $url) use ($replies): MockResponse {
            $key = match (true) {
                str_contains($url, 'api.openstreetmap.org') => 'osm',
                str_contains($url, 'wbgetentities') => 'entities',
                str_contains($url, 'wbgetclaims') && str_contains($url, 'property=P571') => 'founded',
                str_contains($url, 'wbgetclaims') && str_contains($url, 'property=P1082') => 'population',
                str_contains($url, 'nl.wikipedia.org/api/rest_v1') => 'summary_nl',
                str_contains($url, 'en.wikipedia.org/api/rest_v1') => 'summary_en',
                str_contains($url, 'query.wikidata.org') => 'sparql',
                default => null,
            };
            if (null === $key || !\array_key_exists($key, $replies)) {
                return new MockResponse('', ['http_code' => 404]);
            }
            $body = $replies[$key];

            return $body instanceof MockResponse ? $body : new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });
    }
}
