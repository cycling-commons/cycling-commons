<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\Links\LinkVerdictStore;
use App\Catalog\Links\SafeBrowsing;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Where a Safe Browsing verdict lives (catalog-data-model.md §7 `links`).
 *
 * The verdict is keyed by URL in its OWN table, never inside the `links`
 * attribute, because `links` flows through the wizard's change diff and a
 * verdict written there would manufacture curator work out of a background
 * check. It is read by the curator review screens only.
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

        // A NEW instance, so the read goes to the table and not to anything
        // the writing instance might hold.
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

    public function testAVerdictNeverMovesTheMapsCatalogStamps(): void
    {
        // The map shows links whatever their verdict (owner 2026-10-10), so a
        // verdict crossing into or out of unsafe changes nothing a visitor
        // sees, and must not make every region rebuild.
        $before = (int) $this->db->fetchOne('SELECT COUNT(*) FROM catalog_change');

        $this->store->record(['https://crossing.example/' => SafeBrowsing::UNSAFE]);
        $this->store->record(['https://crossing.example/' => SafeBrowsing::SAFE]);
        $this->db->executeStatement('DELETE FROM link_verdict');

        self::assertSame($before, (int) $this->db->fetchOne('SELECT COUNT(*) FROM catalog_change'));
    }
}
