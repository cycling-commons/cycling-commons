<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

/**
 * The one URL of each /best filter state.
 *
 * Every filter on the best-of page is a link, so every combination is a URL,
 * and each URL is its own page-cache entry. Empty parameters, parameters in
 * another order, an unknown value or a filter the chosen category never shows
 * would each make one more URL for the same page: one more cache MISS, and
 * for a crawler one more page to follow (devOps 2026-09-28: ClaudeBot walked
 * /best at 8.7 database connections a second). normalize() maps any query to
 * the single form; the page redirects every other form to it (301), and every
 * filter link is built in it directly.
 *
 * The form, in this order, each only when it says something: `cat` (not the
 * first category, which is the page's own default), `season`, then for the
 * category that shows them `bike` (one known value, the first one asked),
 * `diff` and `len` (known values, once each, in the vocabulary's order), then
 * `cc` (a country with a region).
 *
 * @see docs/specs/page-caching.md §3.2
 *
 * @api
 */
final class BestOfFilters
{
    /** The only category whose page shows the bike, effort and length filters. */
    public const ItemType ROUTE_FILTERS_CATEGORY = ItemType::QualityRides;

    /**
     * Read once per request: every filter link on the page asks.
     *
     * @var list<string>|null
     */
    private ?array $countryCodes = null;

    public function __construct(private readonly RegionRegistryProvider $regions)
    {
    }

    /**
     * @param array<string, mixed> $query a request's query, or a link's wanted parameters
     *
     * @return array<string, string> the normal form, in its order
     */
    public function normalize(array $query): array
    {
        $out = [];
        $categories = BestOfPreview::categories();
        $type = ItemType::fromParam(self::scalar($query['cat'] ?? null));
        if (!$type->isVotable()) {
            $type = $categories[0];
        }
        if ($type !== $categories[0]) {
            $out['cat'] = $type->value;
        }

        $season = Season::tryFrom(self::scalar($query['season'] ?? null));
        if (null !== $season) {
            $out['season'] = $season->value;
        }

        if (self::ROUTE_FILTERS_CATEGORY === $type) {
            foreach ([
                'bike' => BikeType::values(),
                'diff' => array_values(DifficultyVocabulary::LABELS),
                'len' => array_keys(BestOfPreview::LENGTHS),
            ] as $key => $vocabulary) {
                $chosen = array_map(trim(...), explode(',', self::scalar($query[$key] ?? null)));
                $kept = array_values(array_filter($vocabulary, static fn (string $v): bool => \in_array($v, $chosen, true)));
                if ('bike' === $key) {
                    // One bike: a narrowed list is the votes cast on that bike (route-domain.md §8d).
                    $asked = array_values(array_filter($chosen, static fn (string $v): bool => \in_array($v, $vocabulary, true)));
                    $kept = \array_slice($asked, 0, 1);
                }
                if ([] !== $kept) {
                    $out[$key] = implode(',', $kept);
                }
            }
        }

        $cc = strtoupper(self::scalar($query['cc'] ?? null));
        if ('' !== $cc && \in_array($cc, $this->countryCodes(), true)) {
            $out['cc'] = $cc;
        }

        return $out;
    }

    /**
     * The country codes the scope picker may offer: those with an operational
     * region, so a choice never leads to a list that was always going to be
     * empty.
     *
     * @return list<string>
     */
    public function countryCodes(): array
    {
        if (null === $this->countryCodes) {
            $codes = [];
            foreach ($this->regions->all() as $region) {
                $codes[(string) $region['countryCode']] = true;
            }
            $this->countryCodes = array_map(strval(...), array_keys($codes));
        }

        return $this->countryCodes;
    }

    private static function scalar(mixed $value): string
    {
        return \is_string($value) || \is_int($value) ? (string) $value : '';
    }
}
