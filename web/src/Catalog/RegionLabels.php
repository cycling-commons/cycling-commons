<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

use Doctrine\DBAL\Connection;
use Symfony\Contracts\Service\ResetInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Region and country display labels, from `region.labels` and `country.labels`.
 *
 * A label falls back to English, then to the row's name, so a country
 * onboarded without a word in some language still reads as something.
 * `app:country:label` edits one label.
 *
 * @api
 */
final class RegionLabels implements ResetInterface
{
    /** @var array<string, array{name: string, labels: array<string, string>}>|null */
    private ?array $regions = null;

    /** @var array<string, array{name: string, labels: array<string, string>}>|null */
    private ?array $countries = null;

    public function __construct(
        private readonly Connection $db,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function label(string $slug, ?string $fallbackName = null, ?string $locale = null): string
    {
        $this->regions ??= $this->load('SELECT slug AS k, name, labels::text AS labels FROM region');
        $row = $this->regions[$slug] ?? null;
        if (null === $row) {
            return $fallbackName ?? $slug;
        }

        return self::pick($row, $locale ?? $this->translator->getLocale());
    }

    public function countryLabel(string $countryCode, ?string $locale = null): string
    {
        $cc = strtoupper(trim($countryCode));
        $this->countries ??= $this->load('SELECT code AS k, name, labels::text AS labels FROM country');
        $row = $this->countries[$cc] ?? null;
        if (null === $row) {
            return $cc;
        }

        return self::pick($row, $locale ?? $this->translator->getLocale());
    }

    #[\Override]
    public function reset(): void
    {
        $this->regions = null;
        $this->countries = null;
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
