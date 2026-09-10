<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\Links\LinkVerdictStore;
use App\Catalog\Links\SafeBrowsing;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Where a Safe Browsing verdict lives, and what it does at render time
 * (catalog-data-model.md §7 `links`).
 *
 * Two properties here are the design, not features:
 *
 *  - the verdict is keyed by URL in its OWN table, never inside the `links`
 *    attribute, because `links` flows through the wizard's change diff and a
 *    verdict written there would manufacture curator work out of a background
 *    check;
 *  - the render side fails CLOSED for a url already judged unsafe, while
 *    leaving an unknown one alone. Those two directions are the whole point of
 *    having both a submit-side and a render-side check, and a regression in
 *    either is silent.
 */
final class LinkVerdictStoreTest extends KernelTestCase
{
    private LinkVerdictStore $store;
    private Connection $db;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->store = static::getContainer()->get(LinkVerdictStore::class);
        $this->db = static::getContainer()->get(Connection::class);
        $this->db->executeStatement('DELETE FROM link_verdict');
    }

    /** @param array<string, string> $verdicts */
    private function fresh(array $verdicts): LinkVerdictStore
    {
        $this->store->record($verdicts);

        // A NEW instance, because unsafeUrls() memoizes for the request and a
        // test that read its own cache would prove nothing about the query.
        return new LinkVerdictStore($this->db, static::getContainer()->get('clock'));
    }

    public function testAVerdictIsStoredAgainstTheUrlAndReadBack(): void
    {
        $store = $this->fresh([
            'https://bad.example/a' => SafeBrowsing::UNSAFE,
            'https://good.example/b' => SafeBrowsing::SAFE,
        ]);

        self::assertSame([
            'https://bad.example/a' => SafeBrowsing::UNSAFE,
            'https://good.example/b' => SafeBrowsing::SAFE,
            // Never asked about, and it must not read as safe.
            'https://never.example/c' => SafeBrowsing::UNKNOWN,
        ], $store->verdictsFor([
            'https://bad.example/a',
            'https://good.example/b',
            'https://never.example/c',
        ]));
    }

    public function testTheNewestAnswerReplacesTheOlderOne(): void
    {
        $this->store->record(['https://turned.example/' => SafeBrowsing::SAFE]);
        $store = $this->fresh(['https://turned.example/' => SafeBrowsing::UNSAFE]);

        self::assertSame(
            [SafeBrowsing::UNSAFE],
            array_values($store->verdictsFor(['https://turned.example/'])),
        );
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM link_verdict'));
    }

    public function testAnUnsafeUrlIsWithheldAndAnEmptiedEntryDisappears(): void
    {
        $store = $this->fresh(['https://bad.example/a' => SafeBrowsing::UNSAFE]);

        $links = [
            ['label' => 'Only bad', 'urls' => [['url' => 'https://bad.example/a']]],
            ['label' => 'Mixed', 'urls' => [
                ['url' => 'https://bad.example/a', 'locale' => 'en'],
                ['url' => 'https://fine.example/b', 'locale' => 'fr'],
            ]],
        ];

        self::assertSame([
            ['label' => 'Mixed', 'urls' => [['url' => 'https://fine.example/b', 'locale' => 'fr']]],
        ], $store->withhold($links));
    }

    /**
     * The direction that is easy to get backwards. An UNKNOWN url still
     * renders: withholding everything unchecked would empty the map the day a
     * key expired, which is a failure mode a security control is not allowed to
     * have. Unknown is treated generously at RENDER and strictly nowhere -
     * strictness lives in the stored `unsafe`.
     */
    public function testAnUnknownUrlStillRenders(): void
    {
        $store = $this->fresh(['https://asked.example/' => SafeBrowsing::UNKNOWN]);

        $links = [['urls' => [['url' => 'https://asked.example/'], ['url' => 'https://never-asked.example/']]]];

        self::assertSame($links, $store->withhold($links));
    }

    public function testAStaleVerdictIsOfferedToTheSweepAndAFreshOneIsNot(): void
    {
        $this->store->record([
            'https://old.example/' => SafeBrowsing::SAFE,
            'https://new.example/' => SafeBrowsing::SAFE,
        ]);
        $this->db->executeStatement(
            "UPDATE link_verdict SET checked_at = NOW() - INTERVAL '30 days' WHERE url = ?",
            ['https://old.example/'],
        );

        self::assertSame(['https://old.example/'], $this->store->stale(10));
    }

    /** The verdict never touches the attribute it is about. */
    public function testNothingIsWrittenIntoTheLinksAttribute(): void
    {
        $store = $this->fresh(['https://bad.example/a' => SafeBrowsing::UNSAFE]);
        $links = [['label' => 'Site', 'urls' => [['url' => 'https://fine.example/b']]]];

        $out = $store->withhold($links);

        self::assertSame($links, $out);
        self::assertSame(
            ['label', 'urls'],
            array_keys($out[0]),
            'withhold() filters urls and adds no verdict key of its own',
        );
    }
}
