<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

/**
 * Provenance of a catalog row. `Manual` is seeded/hand-authored and never upserted by harvest import.
 *
 * @see docs/specs/catalog-data-model.md §5
 *
 * @api
 */
enum ItemSource: string
{
    case Osm = 'osm';
    case Pivot = 'pivot';
    case Wikidata = 'wikidata';
    case User = 'user';
    /** How it arrived, not verification — the server never saw the ride file. docs/specs/moderation-and-contribution.md (Scout intake) */
    case Scout = 'scout';
    case Manual = 'manual';
    case Auto = 'auto';
}
