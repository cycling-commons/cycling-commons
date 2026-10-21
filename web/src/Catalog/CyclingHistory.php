<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

use App\Catalog\Import\OutboundLinks;
use App\Media\LicenceUrls;
use App\Media\PhotoPlace;
use App\Media\PhotoValidator;
use Doctrine\DBAL\Connection;
use Symfony\Component\Intl\Countries;

/**
 * The places of the cycling history page: every History & culture place on
 * the map that tells a cycling story, in the order the stories happened.
 *
 * Read from the served rows, so a rider's edit or a new place shows here the
 * same minute it shows on the map, and a place the map does not serve (no
 * region, gone, waiting for a curator) is not here either. A photo shows
 * when the map would show it (PhotoValidator) and is our own stored copy: a
 * Commons file still waiting for `app:media:localise-commons` is left out,
 * because a browser never loads a picture from Wikimedia (CspSubscriber).
 *
 * @see docs/specs/cycling-history.md
 *
 * @api
 */
final readonly class CyclingHistory
{
    public function __construct(private Connection $db)
    {
    }

    /**
     * @return array{
     *     dated: list<array{year: int, places: list<array{id: int, name: string, kind: ?string, region: ?string, country: string, story: string, links: list<array{label: string, href: string, domain: string}>, photo: ?array{src: string, credit: string, creditUrl: string, license: string, licenseUrl: ?string, source: string}}>}>,
     *     undated: list<array{id: int, name: string, kind: ?string, region: ?string, country: string, story: string, links: list<array{label: string, href: string, domain: string}>, photo: ?array{src: string, credit: string, creditUrl: string, license: string, licenseUrl: ?string, source: string}}>
     * }
     */
    public function timeline(string $locale): array
    {
        /** @var list<array{id: int|string, name: string, country_code: ?string, attributes: string, region: ?string, lat: float|string, lng: float|string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT i.id, i.name, i.country_code, i.attributes, r.name AS region,
                    ST_Y(ST_PointOnSurface(i.geom)) AS lat, ST_X(ST_PointOnSurface(i.geom)) AS lng
               FROM item i
               JOIN region r ON r.id = i.region_id
              WHERE i.letter = :letter
                AND i.state IN '.ItemState::servedSqlTuple().'
                AND '.GoneRows::notGoneSql('i')."
                AND btrim(COALESCE(i.attributes->>'cyclingStory', '')) <> ''",
            ['letter' => ItemType::HistoryCulture->letter()],
        );

        $byYear = [];
        $undated = [];
        foreach ($rows as $row) {
            /** @var array<string, mixed> $attributes */
            $attributes = json_decode($row['attributes'], true, 512, \JSON_THROW_ON_ERROR);
            $cc = (string) $row['country_code'];
            $kind = \is_string($attributes['type'] ?? null) ? $attributes['type'] : null;
            $place = [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'kind' => null !== $kind ? PlaceKind::label(ItemType::HistoryCulture->letter(), $kind) : null,
                'region' => $row['region'],
                'country' => '' !== $cc && Countries::exists($cc) ? Countries::getName($cc, $locale) : $cc,
                'story' => trim((string) $attributes['cyclingStory']),
                'links' => OutboundLinks::forLocale($attributes['links'] ?? null, $locale),
                'photo' => self::photo($attributes, (float) $row['lat'], (float) $row['lng']),
            ];
            $year = $attributes['year'] ?? null;
            if (\is_string($year) && 1 === preg_match(CatalogField::YEAR_PATTERN, $year)) {
                $byYear[(int) $year][] = $place;
            } else {
                $undated[] = $place;
            }
        }

        ksort($byYear);
        $byName = static fn (array $a, array $b): int => strcmp($a['name'], $b['name']);
        $dated = [];
        foreach ($byYear as $year => $places) {
            usort($places, $byName);
            $dated[] = ['year' => $year, 'places' => $places];
        }
        usort($undated, $byName);

        return ['dated' => $dated, 'undated' => $undated];
    }

    /**
     * The photo the map would show for this place, as the page draws it.
     *
     * @param array<string, mixed> $attributes
     *
     * @return array{src: string, credit: string, creditUrl: string, license: string, licenseUrl: ?string, source: string}|null
     */
    private static function photo(array $attributes, float $lat, float $lng): ?array
    {
        $shown = PhotoValidator::sift($attributes, PhotoPlace::of(ItemType::HistoryCulture->letter(), $lat, $lng))['attributes'];
        $photo = $shown['photo'] ?? null;
        if (!\is_array($photo) || !\is_string($photo['sm'] ?? null) || str_contains($photo['sm'], 'wikimedia.org')) {
            return null;
        }
        $license = \is_string($photo['license'] ?? null) ? $photo['license'] : '';

        return [
            'src' => $photo['sm'],
            'credit' => \is_string($photo['credit'] ?? null) ? $photo['credit'] : '',
            'creditUrl' => \is_string($photo['creditUrl'] ?? null) ? $photo['creditUrl'] : '',
            'license' => $license,
            'licenseUrl' => LicenceUrls::urlFor($license),
            'source' => \is_string($photo['source'] ?? null) ? $photo['source'] : '',
        ];
    }
}
