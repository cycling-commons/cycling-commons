<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Town\TownSummaryRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The town card's poll (docs/specs/map-and-search.md §6.5): the first reader
 * claims and queues, everybody after reads the row. The client names an
 * OpenStreetMap element and a language, never a page.
 */
final class TownControllerTest extends WebTestCase
{
    public function testFirstVisitClaimsOnceAndAnswersPending(): void
    {
        $client = $this->browser();
        $this->clear('relation/999990101');

        $client->request('GET', '/map/town/relation/999990101?lang=nl&lat=51.22&lng=4.40');
        self::assertResponseIsSuccessful();
        self::assertSame('pending', $this->payload($client)['state']);
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));

        $client->request('GET', '/map/town/relation/999990101?lang=nl&lat=51.22&lng=4.40');
        self::assertSame('pending', $this->payload($client)['state']);

        /** @var Connection $db */
        $db = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertSame(1, (int) $db->fetchOne(
            "SELECT COUNT(*) FROM town_summary WHERE osm_ref = 'relation/999990101' AND lang = 'nl'",
        ), 'a second reader must not create a second row, and so cannot queue a second lookup');
    }

    public function testAnAnsweredRowIsServedWithItsTextRacesAndPhotoState(): void
    {
        $client = $this->browser();
        $this->clear('node/999990102');
        /** @var TownSummaryRepository $towns */
        $towns = self::getContainer()->get(TownSummaryRepository::class);
        $towns->claim('node/999990102', 'en');
        $towns->record('node/999990102', 'en', 'Q99999802',
            ['title' => 'Bruges', 'extract' => 'Bruges is a city.', 'url' => 'https://en.wikipedia.org/wiki/Bruges', 'lang' => 'en'],
            [['qid' => 'Q99999813', 'label' => 'Tour of Flanders', 'rels' => ['start'], 'n' => 21, 'last' => 2025, 'url' => 'https://en.wikipedia.org/wiki/Tour_of_Flanders']],
            ['population' => ['n' => 118509, 'year' => 2023]]);

        $client->request('GET', '/map/town/node/999990102?lang=en');
        self::assertResponseIsSuccessful();
        $data = $this->payload($client);
        self::assertSame('ready', $data['state']);
        self::assertSame('Bruges is a city.', $data['text']['extract']);
        self::assertSame('CC BY-SA 4.0', $data['text']['license']);
        self::assertSame('Tour of Flanders', $data['cycling'][0]['label']);
        self::assertSame(118509, $data['facts']['population']['n']);
        self::assertArrayNotHasKey('founded', $data['facts'], 'a missing fact is absent, not null');
        self::assertSame('none', $data['photo']['state'], 'no coordinates means no bucket, so no photo: said, not spun on');
        self::assertFalse($data['edited'], 'fetched, not written by a curator');
    }

    public function testAnAnsweredRowWithoutAPageIsReadyAndEmpty(): void
    {
        $client = $this->browser();
        $this->clear('node/999990103');
        /** @var TownSummaryRepository $towns */
        $towns = self::getContainer()->get(TownSummaryRepository::class);
        $towns->claim('node/999990103', 'en');
        $towns->record('node/999990103', 'en', null, null, []);

        $client->request('GET', '/map/town/node/999990103?lang=en');
        $data = $this->payload($client);
        self::assertSame('ready', $data['state']);
        self::assertNull($data['text']);
        self::assertSame([], $data['cycling']);
        self::assertSame([], $data['facts'], 'an empty object in JSON');
    }

    public function testALanguageWeDoNotSpeakFallsBackToEnglish(): void
    {
        $client = $this->browser();
        $this->clear('node/999990104');

        $client->request('GET', '/map/town/node/999990104?lang=xx');
        self::assertSame('pending', $this->payload($client)['state']);
        /** @var Connection $db */
        $db = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertSame('en', $db->fetchOne("SELECT lang FROM town_summary WHERE osm_ref = 'node/999990104'"));
    }

    public function testAnElementTypeWeDoNotTakeIs404(): void
    {
        $client = $this->browser();
        $client->request('GET', '/map/town/changeset/1');
        self::assertResponseStatusCodeSame(404);
    }

    private function browser(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    private function clear(string $ref): void
    {
        /** @var Connection $db */
        $db = self::getContainer()->get('doctrine.dbal.default_connection');
        $db->executeStatement('DELETE FROM town_summary WHERE osm_ref = :r', ['r' => $ref]);
    }

    /** @return array<string, mixed> */
    private function payload(KernelBrowser $client): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }
}
