<?php
// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\ServiceKind;
use PHPUnit\Framework\TestCase;

/**
 * Cross-language contract test (coverage-provider.md §7):
 * pipeline/contract/coverage-contract.json is the single source of truth for
 * the coverage pipeline's OSM selectors, tile properties, and serviceKind
 * mapping. This test pins it to the PHP side — ServiceKind::fromOsmTags()
 * and the osm-data-architecture.md §5 letter catalogue — so the Python job
 * and the Symfony serving plane cannot drift apart.
 *
 * Skips (rather than fails) when the contract file is absent, so the web
 * suite stays runnable from a checkout of web/ alone.
 */
final class CoverageContractTest extends TestCase
{
    /**
     * @return array{version: int, letters: array<string, array{selectors: list<array{tag: string, label: string}>, tileProps: list<string>}>, serviceKind: array<string, string>}
     */
    private function loadContract(): array
    {
        $path = \dirname(__DIR__, 3).'/pipeline/contract/coverage-contract.json';
        if (!is_file($path)) {
            self::markTestSkipped('pipeline/contract/coverage-contract.json is not present in this checkout');
        }

        /** @var array{version: int, letters: array<string, array{selectors: list<array{tag: string, label: string}>, tileProps: list<string>}>, serviceKind: array<string, string>} */
        return json_decode((string) file_get_contents($path), true, flags: \JSON_THROW_ON_ERROR);
    }

    public function testLettersMatchTheOsmArchCatalogue(): void
    {
        $contract = $this->loadContract();

        self::assertSame(1, $contract['version']);
        // osm-data-architecture.md §5 point catalogue: C D E G H I J.
        // A (road surface) is corridor data and stays out of the coverage
        // artifact (coverage-provider.md §4);
        // B/F/K are category-3 (our own data, never part of the OSM extract).
        self::assertSame(['C', 'D', 'E', 'G', 'H', 'I', 'J'], array_keys($contract['letters']));

        foreach ($contract['letters'] as $letter => $spec) {
            self::assertNotSame([], $spec['selectors'], sprintf('letter %s has no selectors', $letter));
            foreach ($spec['selectors'] as $selector) {
                self::assertSame(['tag', 'label'], array_keys($selector), sprintf('letter %s: selectors are {tag, label} objects', $letter));
                self::assertStringContainsString('=', $selector['tag'], sprintf('letter %s: tag must be key=value', $letter));
                self::assertNotSame('', $selector['label'], sprintf('letter %s: label must be non-empty', $letter));
            }
        }
    }

    public function testServiceKindMappingMatchesFromOsmTags(): void
    {
        $mapping = $this->loadContract()['serviceKind'];

        // Every contract rule resolves to the same kind PHP derives at import…
        foreach ($mapping as $rule => $kind) {
            [$key, $value] = explode('=', $rule, 2);
            self::assertSame($kind, ServiceKind::fromOsmTags([$key => $value])?->value, sprintf('%s must map to %s', $rule, $kind));
        }

        // …and every PHP kind is reachable from the contract (no orphan case).
        self::assertSame(
            array_map(static fn (ServiceKind $kind): string => $kind->value, ServiceKind::cases()),
            array_values($mapping),
        );
    }

    public function testServiceKindRulesAreExactlyTheDSelectors(): void
    {
        $contract = $this->loadContract();

        $dRules = array_map(
            static fn (array $selector): string => $selector['tag'],
            $contract['letters']['D']['selectors'],
        );

        self::assertSame($dRules, array_keys($contract['serviceKind']));
    }

    public function testTilePropsCarryTheLetterSpecificExtras(): void
    {
        $letters = $this->loadContract()['letters'];

        // coverage-provider.md §4: ref/n/t are implicit
        // on every layer; tileProps lists only the per-letter extras.
        self::assertSame(['potable'], $letters['C']['tileProps']);
        self::assertSame(['kind'], $letters['D']['tileProps']);
        self::assertSame(['acc'], $letters['E']['tileProps']);
        foreach (['G', 'H', 'I', 'J'] as $letter) {
            self::assertSame([], $letters[$letter]['tileProps']);
        }
    }
}
