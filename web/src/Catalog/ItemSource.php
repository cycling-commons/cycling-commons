<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

/**
 * Provenance of a catalog row ([OSM]/[auto]/… tags from the edit-items spec).
 *
 * @api Catalog domain enum.
 */
enum ItemSource: string
{
    case Osm = 'osm';
    case Pivot = 'pivot';
    case Wikidata = 'wikidata';
    case User = 'user';
    case Auto = 'auto';
}
