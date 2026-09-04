<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Provider;

use Doctrine\DBAL\Connection;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Who to credit for a row on the map, keyed by provider.
 *
 * The map used to hold this as a front-end constant:
 * `pivot:'Tourisme Wallonie (CC-BY)'` in `assets/map/i18n.js`. One provider
 * fits in a constant. A second one means the constant and the database
 * disagree the moment a curator edits a licence, and every added provider
 * means a deploy to say its name.
 *
 * So the payload carries the citations with the catalog, and the drawer looks
 * up what it was given. Small on purpose: name, homepage, licence label and
 * the attribution line, which is everything a drawer line needs and nothing a
 * reader cannot already see.
 *
 * Cached in `cache.app` next to the rest of the catalog, and invalidated by
 * the desk that edits the rows (data-provider-hierarchy.md §8). A miss is one
 * query over a table with a handful of rows.
 *
 * @see docs/specs/data-provider-hierarchy.md §7, §9.1
 *
 * @api
 */
final class ProviderCitations
{
    /**
     * The trailing number is the SHAPE of the cached array, not the data.
     *
     * A deploy that adds a field to the citation would otherwise serve the
     * old shape until something happened to invalidate the pool, and the map
     * would read a key that is not there yet. Bump it whenever the array
     * below gains or loses a field; the old entry expires on its own.
     */
    private const string CACHE_KEY = 'provider.citations.v2';

    public function __construct(
        private readonly Connection $db,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * Every enabled provider, keyed by its slug.
     *
     * The slug is what a feature carries as `pk`, so the drawer resolves a
     * citation with one lookup and no knowledge of who exists.
     *
     * @return array<string, array{name: string, fullName: string, homepage: string, licence: string, attribution: ?string}>
     */
    public function all(): array
    {
        $map = $this->cache->get(self::CACHE_KEY, function (ItemInterface $_item): array {
            /** @var list<array{provider_key: string, name: string, full_name: string, homepage: string, licence: string, attribution: ?string}> $rows */
            $rows = $this->db->fetchAllAssociative(
                'SELECT provider_key, name, full_name, homepage, licence, attribution
                   FROM data_provider
                  WHERE enabled = TRUE
                  ORDER BY rank DESC',
            );

            $out = [];
            foreach ($rows as $row) {
                $out[$row['provider_key']] = [
                    'name' => $row['name'],
                    'fullName' => $row['full_name'],
                    'homepage' => $row['homepage'],
                    'licence' => $row['licence'],
                    'attribution' => $row['attribution'],
                ];
            }

            return $out;
        });

        return $map;
    }

    public function invalidate(): void
    {
        $this->cache->delete(self::CACHE_KEY);
    }
}
