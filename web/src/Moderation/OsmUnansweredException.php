<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Moderation;

/**
 * A new place cannot be admitted while nobody has said whether it is in OSM.
 *
 * Not an error in the rider's submission: an answer the curator has not given
 * yet (catalog-data-model.md §5b).
 *
 * @api
 */
final class OsmUnansweredException extends \RuntimeException
{
}
