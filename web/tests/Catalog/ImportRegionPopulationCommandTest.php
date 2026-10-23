<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Build-time Wikidata population for region pages
 * (moderation-and-contribution.md §3.1g): written to `region.facts_harvest`,
 * never to the figures people set, refused whole when an entry is malformed,
 * and dropped by --prune for regions the artifact no longer names.
 */
final class ImportRegionPopulationCommandTest extends KernelTestCase
{
    private Connection $db;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = static::getContainer()->get(Connection::class);
    }

    public function testWritesTheCountBesideWhatAPersonSetAndReportsUnknownSlugs(): void
    {
        $id = $this->makeRegion('pop-test-a', '{"population": {"value": 1, "year": 2025, "submissionId": 7, "at": "2026-10-23T00:00:00+00:00"}}');

        $tester = $this->run_(['pop-test-a' => ['population' => 491792, 'year' => 2017, 'qid' => 'Q772'], 'no-such-region' => ['population' => 5, 'year' => 2020, 'qid' => 'Q1']]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('no-such-region', $tester->getDisplay());
        self::assertSame(['population' => ['qid' => 'Q772', 'year' => 2017, 'value' => 491792]], $this->column($id, 'facts_harvest'));
        self::assertSame(1, $this->column($id, 'facts')['population']['value'] ?? null, 'a person\'s figure is never touched');
    }

    public function testAMalformedEntryStopsTheImportBeforeAnythingIsWritten(): void
    {
        $id = $this->makeRegion('pop-test-b');
        foreach ([
            ['population' => 12.5, 'year' => 2017, 'qid' => 'Q772'],
            ['population' => 100, 'year' => 1850, 'qid' => 'Q772'],
            ['population' => 100, 'year' => 2999, 'qid' => 'Q772'],
            ['population' => 100, 'year' => 2017, 'qid' => '772'],
            ['population' => 100, 'year' => 2017],
        ] as $bad) {
            $tester = $this->run_(['pop-test-b' => ['population' => 3, 'year' => 2020, 'qid' => 'Q3'], 'pop-test-zz' => $bad]);
            self::assertSame(Command::FAILURE, $tester->getStatusCode(), json_encode($bad) ?: '');
            self::assertNull($this->column($id, 'facts_harvest'), 'nothing written');
        }
    }

    public function testPruneDropsTheCountOfARegionNoLongerNamed(): void
    {
        $kept = $this->makeRegion('pop-test-c');
        $gone = $this->makeRegion('pop-test-d');
        $this->run_(['pop-test-c' => ['population' => 10, 'year' => 2020, 'qid' => 'Q10'], 'pop-test-d' => ['population' => 20, 'year' => 2020, 'qid' => 'Q20']]);

        $this->run_(['pop-test-c' => ['population' => 11, 'year' => 2021, 'qid' => 'Q10']], true);

        self::assertSame(11, $this->column($kept, 'facts_harvest')['population']['value'] ?? null);
        self::assertNull($this->column($gone, 'facts_harvest'));
    }

    private function makeRegion(string $slug, ?string $facts = null): int
    {
        return (int) $this->db->fetchOne(
            "INSERT INTO region (slug, name, country_code, facts, created_at, updated_at)
             VALUES (:slug, :slug, 'BE', CAST(:facts AS jsonb), NOW(), NOW()) RETURNING id",
            ['slug' => $slug, 'facts' => $facts],
        );
    }

    /** @return array<string, mixed>|null */
    private function column(int $id, string $column): ?array
    {
        $raw = $this->db->fetchOne(sprintf('SELECT %s FROM region WHERE id = :id', $column), ['id' => $id]);
        $decoded = \is_string($raw) ? json_decode($raw, true) : null;

        return \is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed> $artifact */
    private function run_(array $artifact, bool $prune = false): CommandTester
    {
        $path = tempnam(sys_get_temp_dir(), 'pop');
        self::assertNotFalse($path);
        file_put_contents($path, json_encode($artifact, \JSON_THROW_ON_ERROR));
        $tester = new CommandTester((new Application(self::$kernel))->find('app:regions:import-population'));
        $tester->execute(['artifact' => $path] + ($prune ? ['--prune' => true] : []));
        unlink($path);

        return $tester;
    }
}
