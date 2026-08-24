<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\Import\NameKey;
use PHPUnit\Framework\TestCase;

/**
 * The PHP side of the name-key contract (`tools/wikimedia/name_key_cases.json`).
 *
 * `tools/wikimedia/tests/test_name_key_contract.py` asserts the same file from
 * Python. Both must pass, or the import guard
 * (`App\Catalog\Import\DuplicateGuard`) and the seeded-row pre-screen
 * (`prescreen_seeded.py`) disagree about what counts as one name — and that is
 * worse than either being wrong on its own, because one would silently admit
 * the duplicates the other reports.
 *
 * Same shape as `CoverageContractTest`, including the skip: the file lives
 * outside `web/`, so a web-only checkout stays runnable. `ci-app.yml` lists
 * `tools/wikimedia/name_key_cases.json` in its trigger paths so a contract-only
 * edit still re-runs this pin.
 *
 * @see docs/specs/catalog-data-model.md §5
 */
final class NameKeyContractTest extends TestCase
{
    private const string CONTRACT = __DIR__.'/../../../tools/wikimedia/name_key_cases.json';

    /** @return list<array{why: string, name: string, key: string}> */
    private static function cases(): array
    {
        if (!is_file(self::CONTRACT)) {
            self::markTestSkipped('tools/wikimedia/name_key_cases.json is not present in this checkout');
        }

        /** @var array{cases: list<array{why: string, name: string, key: string}>} $data */
        $data = json_decode((string) file_get_contents(self::CONTRACT), true, 512, \JSON_THROW_ON_ERROR);

        return $data['cases'];
    }

    public function testEveryContractCaseHolds(): void
    {
        foreach (self::cases() as $case) {
            self::assertSame(
                $case['key'],
                NameKey::of($case['name']),
                sprintf('%s — NameKey::of(%s)', $case['why'], var_export($case['name'], true)),
            );
        }
    }

    public function testTheContractIsNotEmpty(): void
    {
        // A file that lost its cases would pass the loop above by running none
        // of it, and both languages would report green on nothing.
        self::assertGreaterThanOrEqual(15, \count(self::cases()));
    }

    public function testAnEmptyKeyIsNeverTreatedAsAName(): void
    {
        // Documented in the contract file and load-bearing for the guard: if a
        // punctuation-only name produced a usable key, every one of them would
        // collide with every other.
        self::assertSame('', NameKey::of('---'));
        self::assertSame('', NameKey::of('   '));
        self::assertSame('', NameKey::of(''));
    }
}
