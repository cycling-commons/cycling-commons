<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Support;

use Doctrine\DBAL\Connection;

/**
 * Turns a Commons link a reader pasted into the report form it belongs to.
 *
 * The report door is `/report/{type}/{id}` (content-reports.md §5), and every
 * reportable thing already has a public address: a map deep link, a region
 * page, a rider profile, a photo page. This reads those addresses back. It
 * looks nothing up except a region slug, so pasting a link is never a way to
 * learn whether an id exists (ReportTarget::acceptsId() has the same rule).
 *
 * @see docs/specs/content-reports.md §12
 *
 * @api
 */
final class ReportLinkResolver
{
    public const string OUTCOME_FOUND = 'found';
    /** The point is OpenStreetMap's, not ours: nothing of ours to report. */
    public const string OUTCOME_OSM = 'osm';
    public const string OUTCOME_UNKNOWN = 'unknown';

    private const string UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return array{outcome: string, target?: ReportTarget, id?: string}
     */
    public function resolve(string $url): array
    {
        $url = trim($url);
        if ('' === $url) {
            return ['outcome' => self::OUTCOME_UNKNOWN];
        }
        // A bare path is fine; parse_url() wants a scheme for a full address only.
        $parts = parse_url($url);
        if (false === $parts) {
            return ['outcome' => self::OUTCOME_UNKNOWN];
        }
        $path = rawurldecode($parts['path'] ?? '/');
        // Locale prefix off: the report door itself has none.
        $path = (string) preg_replace('#^/(fr|nl|de|es)(?=/|$)#', '', $path);
        $path = rtrim($path, '/') ?: '/';
        $query = [];
        parse_str($parts['query'] ?? '', $query);

        if (1 === preg_match('#^/riders/('.self::UUID.')$#i', $path, $m)) {
            return $this->found(ReportTarget::DisplayName, strtolower($m[1]));
        }
        if (1 === preg_match('#^/photo/('.self::UUID.')$#i', $path, $m)) {
            return $this->found(ReportTarget::Photo, strtolower($m[1]));
        }
        if (1 === preg_match('#^/(regions|regios|regionen|regiones)/([a-z0-9-]+)$#', $path, $m)) {
            $id = $this->db->fetchOne('SELECT id FROM region WHERE slug = :slug', ['slug' => $m[2]]);

            return false === $id ? ['outcome' => self::OUTCOME_UNKNOWN] : $this->found(ReportTarget::RegionText, (string) $id);
        }
        if ('/map' === $path) {
            // map-and-search.md §8: `item=<id>` and `route=<id>` may carry a
            // readable `/<slug>` tail; the id decides.
            foreach (['item' => ReportTarget::Item, 'route' => ReportTarget::Route] as $key => $target) {
                $raw = $query[$key] ?? null;
                if (\is_string($raw) && 1 === preg_match('#^([1-9][0-9]{0,9})(?:/|$)#', $raw, $m)) {
                    return $this->found($target, $m[1]);
                }
            }
            if (isset($query['ref'])) {
                return ['outcome' => self::OUTCOME_OSM];
            }
        }

        return ['outcome' => self::OUTCOME_UNKNOWN];
    }

    /** @return array{outcome: string, target: ReportTarget, id: string} */
    private function found(ReportTarget $target, string $id): array
    {
        return ['outcome' => self::OUTCOME_FOUND, 'target' => $target, 'id' => $id];
    }
}
