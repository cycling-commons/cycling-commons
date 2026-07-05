<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

/**
 * Provenance of a catalog row ([OSM]/[auto]/… tags from the edit-items spec).
 * `Manual` is a hand-added/seeded row (e.g. demo pins authored directly in the
 * DB) — treated like a rider contribution: never touched by the importer
 * (which only upserts harvested osm/pivot/wikidata rows) and served the same
 * as any other source by CatalogProvider (source-agnostic except letter E).
 *
 * @api Catalog domain enum.
 */
enum ItemSource: string
{
    case Osm = 'osm';
    case Pivot = 'pivot';
    case Wikidata = 'wikidata';
    case User = 'user';
    case Manual = 'manual';
    case Auto = 'auto';
}
