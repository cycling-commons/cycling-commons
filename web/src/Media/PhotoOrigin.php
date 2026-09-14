<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media;

/**
 * Where a photo linked to a place came from.
 *
 * - `Commons`: a Wikimedia Commons file, fetched on demand, harvested, named by
 *   a Wikidata P18, or hotlinked by a seed;
 * - `Rider`: a rider's own upload (`media_upload`), licensed to us at upload;
 * - `Import`: any other photo an import or seed writes, held to the same bar
 *   as a Commons file.
 *
 * @see docs/specs/photo-uploads.md §5h
 *
 * @api
 */
enum PhotoOrigin: string
{
    case Commons = 'commons';
    case Rider = 'rider';
    case Import = 'import';
}
