<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\World;

use PHPUnit\Framework\TestCase;
use Sokil\IsoCodes\IsoCodesFactory;

/**
 * #29: subdivision parent links (and levels) were never populated because the
 * import read subdivisions via getAllByCountryCode() — an INDEX lookup that
 * returns entries with a NULL parent. Plain iteration hydrates the full entry
 * including `parent`, which is what the import now uses. This locks in that
 * distinction so a regression (or a return to the country lookup) is caught
 * (the command itself is a heavy full-world load, not unit-tested).
 */
final class SubdivisionParentDataTest extends TestCase
{
    public function testCountryLookupDropsParentButIterationKeepsIt(): void
    {
        $subDb = (new IsoCodesFactory())->getSubdivisions();

        // The index-based lookup the import used to call returns no parents.
        $viaLookup = [];
        foreach ($subDb->getAllByCountryCode('BE') as $s) {
            if (\is_string($s->getParent()) && '' !== $s->getParent()) {
                $viaLookup[strtoupper($s->getCode())] = strtoupper($s->getParent());
            }
        }
        self::assertSame([], $viaLookup, 'getAllByCountryCode drops parents — the original bug');

        // Iteration (what the import now uses) exposes them.
        $viaIteration = [];
        foreach ($subDb as $s) {
            $code = strtoupper($s->getCode());
            if (str_starts_with($code, 'BE-') && \is_string($s->getParent()) && '' !== $s->getParent()) {
                $viaIteration[$code] = strtoupper($s->getParent());
            }
        }
        self::assertArrayHasKey('BE-VAN', $viaIteration);
        self::assertSame('BE-VLG', $viaIteration['BE-VAN'], 'a Flemish province rolls up to the Flemish Region');
    }
}
