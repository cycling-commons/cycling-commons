<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

use Doctrine\DBAL\Connection;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Service\ResetInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Region and country display labels, from `region.labels` and `country.labels`.
 *
 * A label falls back to English, then to the row's name, so a country
 * onboarded without a word in some language still reads as something.
 * `app:country:label` edits one label.
 *
 * Every page names regions, so both lists are kept in the app cache (Redis)
 * for an hour rather than read on every request. A slug or code missing from
 * the kept list reads the table again at once, so a region onboarded since
 * is named right away; the writers of a label drop the kept lists
 * ({@see self::forget()}).
 *
 * @api
 */
final class RegionLabels implements ResetInterface
{
    private const string REGIONS_KEY = 'region_labels.regions';
    private const string COUNTRIES_KEY = 'region_labels.countries';
    private const int TTL = 3600;

    private const string REGIONS_SQL = 'SELECT slug AS k, name, labels::text AS labels FROM region';
    private const string COUNTRIES_SQL = 'SELECT code AS k, name, labels::text AS labels FROM country';

    /** @var array<string, array{name: string, labels: array<string, string>}>|null */
    private ?array $regions = null;

    /** @var array<string, array{name: string, labels: array<string, string>}>|null */
    private ?array $countries = null;

    /** Whether this request already read each table itself, so a miss reads it once at most. */
    private bool $regionsRead = false;
    private bool $countriesRead = false;

    public function __construct(
        private readonly Connection $db,
        private readonly TranslatorInterface $translator,
        private readonly CacheInterface $cache,
    ) {
    }

    public function label(string $slug, ?string $fallbackName = null, ?string $locale = null): string
    {
        $this->regions ??= $this->kept(self::REGIONS_KEY, self::REGIONS_SQL, $this->regionsRead);
        if (!isset($this->regions[$slug]) && !$this->regionsRead) {
            $this->regions = $this->fresh(self::REGIONS_KEY, self::REGIONS_SQL);
            $this->regionsRead = true;
        }
        $row = $this->regions[$slug] ?? null;
        if (null === $row) {
            return $fallbackName ?? $slug;
        }

        return self::pick($row, $locale ?? $this->translator->getLocale());
    }

    public function countryLabel(string $countryCode, ?string $locale = null): string
    {
        $cc = strtoupper(trim($countryCode));
        $this->countries ??= $this->kept(self::COUNTRIES_KEY, self::COUNTRIES_SQL, $this->countriesRead);
        if (!isset($this->countries[$cc]) && !$this->countriesRead) {
            $this->countries = $this->fresh(self::COUNTRIES_KEY, self::COUNTRIES_SQL);
            $this->countriesRead = true;
        }
        $row = $this->countries[$cc] ?? null;
        if (null === $row) {
            return $cc;
        }

        return self::pick($row, $locale ?? $this->translator->getLocale());
    }

    /** Drop the kept lists, after a label, a name or a region changed. */
    public function forget(): void
    {
        $this->cache->delete(self::REGIONS_KEY);
        $this->cache->delete(self::COUNTRIES_KEY);
        $this->reset();
    }

    #[\Override]
    public function reset(): void
    {
        $this->regions = null;
        $this->countries = null;
        $this->regionsRead = false;
        $this->countriesRead = false;
    }

    /**
     * The kept list, or the table when none is kept. `$read` is set when the
     * table was read for it.
     *
     * @return array<string, array{name: string, labels: array<string, string>}>
     */
    private function kept(string $key, string $sql, bool &$read): array
    {
        return $this->cache->get($key, function (ItemInterface $item) use ($sql, &$read): array {
            $item->expiresAfter(self::TTL);
            $read = true;

            return $this->load($sql);
        });
    }

    /**
     * The table, read now, and kept again.
     *
     * @return array<string, array{name: string, labels: array<string, string>}>
     */
    private function fresh(string $key, string $sql): array
    {
        $this->cache->delete($key);
        $read = false;

        return $this->kept($key, $sql, $read);
    }

    /** @param array{name: string, labels: array<string, string>} $row */
    private static function pick(array $row, string $locale): string
    {
        return $row['labels'][$locale] ?? $row['labels']['en'] ?? $row['name'];
    }

    /** @return array<string, array{name: string, labels: array<string, string>}> */
    private function load(string $sql): array
    {
        $out = [];
        foreach ($this->db->fetchAllAssociative($sql) as $r) {
            $decoded = json_decode(\is_string($r['labels'] ?? null) ? $r['labels'] : '{}', true);
            $labels = [];
            // An empty map may be stored as [] (a list), which carries no labels.
            foreach (\is_array($decoded) ? $decoded : [] as $locale => $text) {
                if (\is_string($locale) && \is_string($text) && '' !== $text) {
                    $labels[$locale] = $text;
                }
            }
            $out[trim((string) $r['k'])] = ['name' => (string) $r['name'], 'labels' => $labels];
        }

        return $out;
    }
}
