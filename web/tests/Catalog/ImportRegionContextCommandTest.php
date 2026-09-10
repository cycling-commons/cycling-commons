<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Build-time Wikipedia context for region pages: the import refuses an
 * extract that arrives without its citation (the text is CC BY-SA 4.0 and
 * the attribution renders from the same entry), reports unknown slugs
 * instead of skipping them silently, and leaves regions absent from the
 * artifact untouched unless --prune says otherwise.
 */
final class ImportRegionContextCommandTest extends KernelTestCase
{
    private Connection $db;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = static::getContainer()->get(Connection::class);
    }

    private function makeRegion(string $slug): int
    {
        return (int) $this->db->fetchOne(
            "INSERT INTO region (slug, name, country_code, created_at, updated_at)
             VALUES (:slug, :slug, 'BE', NOW(), NOW()) RETURNING id",
            ['slug' => $slug],
        );
    }

    /** @param array<string, mixed> $artifact */
    private function run_(array $artifact, bool $prune = false): CommandTester
    {
        $path = tempnam(sys_get_temp_dir(), 'ctx');
        self::assertNotFalse($path);
        file_put_contents($path, json_encode($artifact, \JSON_THROW_ON_ERROR));
        $tester = new CommandTester((new Application(self::$kernel))->find('app:regions:import-context'));
        $tester->execute(['artifact' => $path] + ($prune ? ['--prune' => true] : []));
        unlink($path);

        return $tester;
    }

    public function testImportsPerLocaleEntriesAndReportsUnknownSlugs(): void
    {
        $id = $this->makeRegion('ctx-test-region');
        $entry = ['en' => ['title' => 'T', 'extract' => 'A short lead.', 'url' => 'https://en.wikipedia.org/wiki/T']];

        $tester = $this->run_(['ctx-test-region' => $entry, 'no-such-region' => $entry]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('no-such-region', $tester->getDisplay(), 'unknown slugs are named, never silently dropped');
        $stored = json_decode((string) $this->db->fetchOne('SELECT context FROM region WHERE id = :id', ['id' => $id]), true);
        // assertEquals: jsonb round-trips reorder object keys.
        self::assertEquals($entry, $stored);
    }

    public function testAnExtractWithoutItsCitationIsRefused(): void
    {
        $this->makeRegion('ctx-uncited-region');

        $tester = $this->run_(['ctx-uncited-region' => ['en' => ['title' => 'T', 'extract' => 'Text.', 'url' => '']]]);

        self::assertSame(1, $tester->getStatusCode(), 'CC BY-SA text without a source url must be refused');
        self::assertNull($this->db->fetchOne('SELECT context FROM region WHERE slug = :s', ['s' => 'ctx-uncited-region']) ?: null);
    }

    public function testAbsentRegionsKeepContextUnlessPruned(): void
    {
        $keepId = $this->makeRegion('ctx-keep-region');
        $entry = ['en' => ['title' => 'K', 'extract' => 'Kept.', 'url' => 'https://en.wikipedia.org/wiki/K']];
        $this->run_(['ctx-keep-region' => $entry])->assertCommandIsSuccessful();

        $otherId = $this->makeRegion('ctx-other-region');
        $otherEntry = ['en' => ['title' => 'O', 'extract' => 'Other.', 'url' => 'https://en.wikipedia.org/wiki/O']];

        // Partial artifact without the first region: context survives.
        $this->run_(['ctx-other-region' => $otherEntry])->assertCommandIsSuccessful();
        self::assertNotNull($this->db->fetchOne('SELECT context FROM region WHERE id = :id', ['id' => $keepId]));

        // Same artifact with --prune: now it is cleared, the present one stays.
        $this->run_(['ctx-other-region' => $otherEntry], prune: true)->assertCommandIsSuccessful();
        self::assertNull($this->db->fetchOne('SELECT context FROM region WHERE id = :id', ['id' => $keepId]) ?: null);
        self::assertNotNull($this->db->fetchOne('SELECT context FROM region WHERE id = :id', ['id' => $otherId]));
    }
}
