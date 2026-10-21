<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\Links\LinkSafety;
use App\Catalog\Links\LinkVerdictStore;
use App\Catalog\Links\SafeBrowsing;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A Safe Browsing answer is reused only as long as the terms allow
 * (catalog-data-model.md §7): a match at most its `cacheDuration` and never
 * 30 minutes, a clean answer 10 minutes, an unknown one not at all.
 */
final class LinkSafetyTest extends KernelTestCase
{
    /** @var list<string> the bodies Google was sent */
    private array $asked = [];

    public function testAFreshAnswerIsReusedAndAnExpiredOneAskedAgain(): void
    {
        $safety = $this->safety('{"matches":[{"threat":{"url":"https://bad.example/"},"cacheDuration":"300.000s"}]}');

        self::assertSame(['https://bad.example/' => 'unsafe', 'https://good.example/' => 'safe'], $safety->verdicts(['https://bad.example/', 'https://good.example/']));
        self::assertCount(1, $this->asked);

        self::assertSame(['https://bad.example/' => 'unsafe', 'https://good.example/' => 'safe'], $safety->verdicts(['https://bad.example/', 'https://good.example/']));
        self::assertCount(1, $this->asked, 'a reload within the time asks Google nothing');

        // How long each may be used: the match its cacheDuration, the clean answer 10 minutes.
        self::assertEqualsWithDelta(300, $this->secondsLeft('https://bad.example/'), 5);
        self::assertEqualsWithDelta(600, $this->secondsLeft('https://good.example/'), 5);

        $this->db()->executeStatement("UPDATE link_verdict SET fresh_until = NOW() - INTERVAL '1 second' WHERE url = 'https://bad.example/'");
        $safety->verdicts(['https://bad.example/', 'https://good.example/']);
        self::assertCount(2, $this->asked);
        self::assertStringContainsString('bad.example', $this->asked[1]);
        self::assertStringNotContainsString('good.example', $this->asked[1], 'only the expired one is asked again');
    }

    public function testAnUnknownAnswerIsNeverReused(): void
    {
        $safety = $this->safety(null);

        self::assertSame(['https://down.example/' => 'unknown'], $safety->verdicts(['https://down.example/']));
        $safety->verdicts(['https://down.example/']);
        self::assertCount(2, $this->asked, 'an outage is asked again next time');
        self::assertSame('unknown', $this->db()->fetchOne("SELECT verdict FROM link_verdict WHERE url = 'https://down.example/'"), 'and stored for the desk all the same');
    }

    public function testAMatchIsNeverUsedLongerThanThirtyMinutes(): void
    {
        self::assertSame(1800, SafeBrowsing::matchTtl('86400.000s'));
        self::assertSame(300, SafeBrowsing::matchTtl('300s'));
        self::assertSame(300, SafeBrowsing::matchTtl(null), 'no duration: the shortest the API gives');
        self::assertSame(300, SafeBrowsing::matchTtl('soon'));
    }

    /** A Safe Browsing over a mock web that answers `$body`, or fails when it is null. */
    private function safety(?string $body): LinkSafety
    {
        self::bootKernel();
        $this->db()->executeStatement('DELETE FROM link_verdict');
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($body): MockResponse {
            $this->asked[] = (string) ($options['body'] ?? '');
            if (null === $body) {
                throw new \RuntimeException('connection refused');
            }

            return new MockResponse($body);
        });
        /** @var LinkVerdictStore $store */
        $store = static::getContainer()->get(LinkVerdictStore::class);

        return new LinkSafety(new SafeBrowsing($http, new NullLogger(), 'test-key'), $store);
    }

    private function secondsLeft(string $url): float
    {
        return (float) $this->db()->fetchOne('SELECT EXTRACT(EPOCH FROM fresh_until - checked_at) FROM link_verdict WHERE url = :u', ['u' => $url]);
    }

    private function db(): Connection
    {
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);

        return $db;
    }
}
