<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

/**
 * How a contributor sets a catalog item's location in add mode.
 *
 * @see docs/specs/edit-items/README.md (Common to every type)
 */
enum LocationMode: string
{
    /** Tap the map to drop a single pin (most point types). */
    case Point = 'point';

    /** Tap the start, then the end, drawing a line (road surface). */
    case Segment = 'segment';

    /** No pin; a GPX/FIT track sets the whole route (quality rides). */
    case None = 'none';
}
