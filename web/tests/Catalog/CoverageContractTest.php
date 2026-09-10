<?php

// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\ServiceKind;
use App\Coverage\CoverageRepository;
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
     * @return array{version: int, letters: array<string, array{selectors: list<array{tag: string, label: string}>, tileProps: list<string>}>, serviceKind: array<string, string>, universalTileProps: list<string>, storedTagKeys: list<string>}
     */
    private function loadContract(): array
    {
        $path = \dirname(__DIR__, 3).'/pipeline/contract/coverage-contract.json';
        if (!is_file($path)) {
            self::markTestSkipped('pipeline/contract/coverage-contract.json is not present in this checkout');
        }

        /* @var array{version: int, letters: array<string, array{selectors: list<array{tag: string, label: string}>, tileProps: list<string>}>, serviceKind: array<string, string>, universalTileProps: list<string>, storedTagKeys: list<string>} */
        return json_decode((string) file_get_contents($path), true, flags: \JSON_THROW_ON_ERROR);
    }

    public function testLettersMatchTheOsmArchCatalogue(): void
    {
        $contract = $this->loadContract();

        self::assertSame(1, $contract['version']);
        // osm-data-architecture.md §5 point catalogue: B C D F G O P Q
        // (C · Public toilets added 2026-07-30; the derived ride heatmap has
        // no letter). A (road surface) is corridor data and
        // stays out of the coverage artifact (coverage-provider.md §4);
        // E/N/R are category-3 (our own data, never part of the OSM extract).
        self::assertSame(['B', 'C', 'D', 'F', 'G', 'O', 'P', 'Q'], array_keys($contract['letters']));

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
        // on every layer; tileProps lists only the per-letter extras. Letter B
        // carries two: `potable` (yes / no / absent) and `food`, the shop and
        // eatery half, because the pin draws a kind for each
        // (data-provider-hierarchy.md §6.3a).
        // `cd` on every letter: a dated OSM check_date is a published witness
        // and drops the badge on a coverage disc (data-provider-hierarchy.md §6.7.7).
        self::assertSame(['potable', 'food', 'cd'], $letters['B']['tileProps']);
        self::assertSame(['kind', 'cd'], $letters['D']['tileProps']);
        self::assertSame(['acc', 'cd'], $letters['O']['tileProps']);
        foreach (['F', 'G', 'P', 'Q'] as $letter) {
            self::assertSame(['cd'], $letters[$letter]['tileProps']);
        }
    }

    public function testUniversalTilePropsCarryTheScopeKeys(): void
    {
        // ref/n/t identity + the ridtok/cctok region-scoping tokens
        // (map-and-search.md §4.5 Phase 3 review round: pipe-delimited
        // tokens so cluster bubbles can union them) are emitted on EVERY tile
        // layer by pipeline tiles.py::_letter_sql; per-letter tileProps stay
        // extras-only.
        self::assertSame(['ref', 'n', 't', 'ridtok', 'cctok'], $this->loadContract()['universalTileProps']);
    }

    public function testEveryWhitelistedDisplayTagIsActuallyStored(): void
    {
        // The drawer can only ever render what the pipeline stored: the detail
        // endpoint is served purely from coverage_poi, with no live OSM fallback
        // anywhere in the request path (coverage-provider.md §5). So a key in
        // TAG_WHITELIST that the pipeline trims away is a permanently blank
        // drawer row - this test is what makes the two halves one contract.
        $stored = $this->loadContract()['storedTagKeys'];

        foreach (CoverageRepository::TAG_WHITELIST as $tag) {
            self::assertContains($tag, $stored, sprintf(
                'TAG_WHITELIST carries "%s" but storedTagKeys does not: the drawer would ask for a tag the cache never stores',
                $tag,
            ));
        }
    }

    public function testStoredTagKeysAreSortedUniqueAndExcludePersonalData(): void
    {
        $stored = $this->loadContract()['storedTagKeys'];

        self::assertSame(array_values(array_unique($stored)), $stored, 'storedTagKeys must be unique');
        $sorted = $stored;
        sort($sorted);
        self::assertSame($sorted, $stored, 'storedTagKeys must be sorted (reviewable diffs)');

        // Deliberate exclusions, not oversights (coverage-provider.md §2).
        // `name` is promoted to the coverage_poi.name column. `email` is ~99 %
        // redundant against website/phone and is frequently a private mailbox,
        // so storing it would put personal data in a dataset we describe as
        // non-personal - without any drawer ever showing it.
        foreach (['name', 'email', 'contact:email'] as $excluded) {
            self::assertNotContains($excluded, $stored);
        }
    }
}
