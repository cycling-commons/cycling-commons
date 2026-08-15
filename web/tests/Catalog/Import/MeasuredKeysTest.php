<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Catalog\Import;

use App\Catalog\Import\ItemUpsert;
use PHPUnit\Framework\TestCase;

/**
 * ItemUpsert::MEASURED_KEYS exists in three places that only agree because
 * somebody remembered: the PHP const, the key list embedded in both SQL
 * constants (a heredoc cannot interpolate an array), and the set of keys
 * `app:climbs:recompute --write` actually writes. A key the recompute writes
 * but the SQL does not carry is silently wiped on the next re-seed - the
 * exact failure this mechanism exists to end.
 */
final class MeasuredKeysTest extends TestCase
{
    public function testBothSqlStatementsCarryEveryMeasuredKeyExactly(): void
    {
        foreach ([ItemUpsert::SQL, ItemUpsert::SQL_OVERWRITE_EDITED] as $sql) {
            self::assertSame(1, preg_match('/unnest\\(ARRAY\\[([^\\]]+)\\]\\)/', $sql, $m));
            preg_match_all("/'([^']+)'/", $m[1], $keys);
            self::assertSame(ItemUpsert::MEASURED_KEYS, $keys[1]);
        }
    }

    public function testMeasuredKeysMirrorWhatTheRecomputeWrites(): void
    {
        $source = file_get_contents(__DIR__.'/../../../src/Command/RecomputeClimbProfilesCommand.php');
        self::assertNotFalse($source);
        // Only the persisting block: keys written as $attrs['x'] = ...
        preg_match_all("/\\\$attrs\\['(\\w+)'\\] = /", $source, $m);
        $written = array_values(array_unique($m[1]));
        sort($written);
        $declared = ItemUpsert::MEASURED_KEYS;
        sort($declared);
        self::assertSame($written, $declared,
            'ItemUpsert::MEASURED_KEYS must be exactly the keys RecomputeClimbProfilesCommand writes');
    }
}
