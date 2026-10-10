<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Tests\Coverage\CoverageSchema;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Every table the catalog payload reads moves a region stamp when it
 * changes, or a document built before the change is served after it for up
 * to a day (catalog-data-model.md §9.1). Read from the source, so a new join
 * in CatalogProvider or one of the SQL pieces it composes fails here until
 * its table carries a catalog_change trigger.
 */
final class CatalogChangeCoverageTest extends KernelTestCase
{
    use CoverageSchema;

    /** The files whose SQL builds the payload. */
    private const array SOURCES = [
        'src/Catalog/CatalogProvider.php',
        'src/Catalog/ItemEvidenceResolver.php',
        'src/Catalog/ClaimedOsmRefs.php',
        'src/Catalog/CoverageRetirement.php',
        'src/Catalog/GoneRows.php',
        'src/Provider/ProviderCitations.php',
    ];

    /**
     * Read by those files outside the payload: the heat layer is its own
     * endpoint (CatalogProvider::heat()), and `unnest` is a function the
     * pattern also catches.
     */
    private const array NOT_PAYLOAD = ['heat_point', 'unnest'];

    public function testEveryTableThePayloadReadsCountsItsChanges(): void
    {
        self::bootKernel();
        $db = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        // Created by the pipeline in production; the trait builds it here.
        self::ensureCoverageSchema($db);

        $root = \dirname(__DIR__, 2);
        $tables = [];
        foreach (self::SOURCES as $file) {
            // Upper-case keywords only: the SQL here is written that way, and
            // the prose in the comments ("from the drawer") is not. A name
            // followed by a dot is a column (`IS DISTINCT FROM rr.region_id`).
            preg_match_all('/\b(?:FROM|JOIN)\s+([a-z_]+)\b(?!\.)/', (string) file_get_contents($root.'/'.$file), $m);
            foreach ($m[1] as $table) {
                $tables[$table] = true;
            }
        }
        $tables = array_diff(array_keys($tables), self::NOT_PAYLOAD);
        sort($tables);
        self::assertContains('item', $tables, 'the source scan found the payload query');

        /** @var list<string> $counted */
        $counted = $db->fetchFirstColumn("SELECT DISTINCT tgrelid::regclass::text FROM pg_trigger WHERE tgname LIKE 'catalog\\_change\\_%'");
        self::assertSame([], array_values(array_diff($tables, $counted)), 'tables the payload reads with no catalog_change trigger');
    }
}
