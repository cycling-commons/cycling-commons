<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media\Commons;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Which places name a Wikimedia Commons file, so a command deleting a cached
 * file (`commons_photo` row and stored objects) can tell whether anything may
 * still show it.
 *
 * Two kinds of place name a file:
 *
 * - a catalog item, through an entry in its `photo` or `photos` attribute (the
 *   entry's `source` page URL, or an older entry's hotlinked image URL);
 * - a coverage point, through its `wikimedia_commons` or `image` tag, or its
 *   `wikidata` tag whose P18 image is cached in `wikidata_image`.
 *
 * Names are compared in the normalised form CommonsFile produces (spaces, not
 * underscores; url-decoded), which is the form `commons_photo.file` holds.
 *
 * coverage_poi has millions of rows, so the coverage references for any number
 * of files come from two scans, never one query per file.
 *
 * @see docs/specs/scenic-views.md §8
 *
 * @api
 */
final readonly class CommonsPhotoUsage
{
    public function __construct(private Connection $db)
    {
    }

    /**
     * The Commons file one photo entry names, or null.
     *
     * An entry is an object with a `source` page URL (and image URLs), or, on
     * rows seeded before photos were localised, a bare image URL string. Every
     * string value is read, `source` first, so an entry naming a file in any of
     * them counts.
     */
    public static function fileOf(mixed $entry): ?string
    {
        if (\is_string($entry)) {
            return CommonsFile::fromTags(['image' => $entry]);
        }
        if (!\is_array($entry)) {
            return null;
        }
        $values = $entry;
        if (\array_key_exists('source', $values)) {
            $values = ['source' => $values['source']] + $values;
        }
        foreach ($values as $value) {
            if (\is_string($value)) {
                $file = CommonsFile::fromTags(['image' => $value]);
                if (null !== $file) {
                    return $file;
                }
            }
        }

        return null;
    }

    /**
     * Every Commons file an item's `photo` and `photos` attributes name.
     *
     * @param array<array-key, mixed> $attributes
     *
     * @return list<string>
     */
    public static function filesIn(array $attributes): array
    {
        $entries = [];
        if (\array_key_exists('photo', $attributes)) {
            $entries[] = $attributes['photo'];
        }
        if (\is_array($attributes['photos'] ?? null)) {
            array_push($entries, ...array_values($attributes['photos']));
        }
        $files = [];
        foreach ($entries as $entry) {
            $file = self::fileOf($entry);
            if (null !== $file) {
                $files[$file] = true;
            }
        }

        return array_keys($files);
    }

    /**
     * Which of these files an item's photo attributes still name, leaving out
     * the given items.
     *
     * @param list<string> $files
     * @param list<int>    $exceptItems
     *
     * @return array<string, true>
     */
    public function namedByItems(array $files, array $exceptItems = []): array
    {
        if ([] === $files) {
            return [];
        }
        $wanted = array_fill_keys($files, true);
        $sql = "SELECT attributes->'photo' AS photo, attributes->'photos' AS photos FROM item
                 WHERE (attributes->'photo' IS NOT NULL OR attributes->'photos' IS NOT NULL)";
        $params = [];
        $types = [];
        // An empty NOT IN list would expand to NOT IN (NULL), which matches no row.
        if ([] !== $exceptItems) {
            $sql .= ' AND id NOT IN (:except)';
            $params['except'] = $exceptItems;
            $types['except'] = ArrayParameterType::INTEGER;
        }

        $named = [];
        foreach ($this->db->iterateAssociative($sql, $params, $types) as $row) {
            $attributes = [];
            foreach (['photo', 'photos'] as $key) {
                if (\is_string($row[$key] ?? null)) {
                    $attributes[$key] = json_decode($row[$key], true);
                }
            }
            foreach (self::filesIn($attributes) as $file) {
                if (isset($wanted[$file])) {
                    $named[$file] = true;
                }
            }
        }

        return $named;
    }

    /**
     * The coverage points naming each of these files, with their letter and
     * position. A file no point names is absent from the result.
     *
     * Each tag is read on its own, so a point whose `wikimedia_commons` and
     * `image` name different files counts for both.
     *
     * @param list<string> $files
     *
     * @return array<string, list<array{letter: string, lat: float, lng: float}>>
     */
    public function coveragePoints(array $files): array
    {
        if ([] === $files || null === $this->db->fetchOne("SELECT to_regclass('public.coverage_poi')")) {
            return [];
        }
        $wanted = array_fill_keys($files, true);
        $points = [];

        $tagged = $this->db->iterateAssociative(
            "SELECT letter, ST_Y(geom) AS lat, ST_X(geom) AS lng,
                    tags->>'wikimedia_commons' AS wikimedia_commons, tags->>'image' AS image
               FROM coverage_poi
              WHERE tags->>'wikimedia_commons' IS NOT NULL OR tags->>'image' IS NOT NULL",
        );
        foreach ($tagged as $row) {
            $named = [];
            foreach (['wikimedia_commons', 'image'] as $tag) {
                $file = \is_string($row[$tag] ?? null) ? CommonsFile::fromTags([$tag => $row[$tag]]) : null;
                if (null !== $file && isset($wanted[$file])) {
                    $named[$file] = true;
                }
            }
            foreach (array_keys($named) as $file) {
                $points[$file][] = self::point($row);
            }
        }

        $viaWikidata = $this->db->iterateAssociative(
            "SELECT w.file, c.letter, ST_Y(c.geom) AS lat, ST_X(c.geom) AS lng
               FROM wikidata_image w
               JOIN coverage_poi c ON c.tags->>'wikidata' = w.qid
              WHERE w.file IS NOT NULL",
        );
        foreach ($viaWikidata as $row) {
            $file = $row['file'] ?? null;
            if (\is_string($file) && isset($wanted[$file])) {
                $points[$file][] = self::point($row);
            }
        }

        return $points;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{letter: string, lat: float, lng: float}
     */
    private static function point(array $row): array
    {
        $letter = $row['letter'] ?? '';
        $lat = $row['lat'] ?? 0;
        $lng = $row['lng'] ?? 0;

        return [
            'letter' => trim(\is_string($letter) ? $letter : ''),
            'lat' => is_numeric($lat) ? (float) $lat : 0.0,
            'lng' => is_numeric($lng) ? (float) $lng : 0.0,
        ];
    }
}
