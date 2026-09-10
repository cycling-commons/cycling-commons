<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Town;

/** A third-party source (OpenStreetMap, Wikidata, Wikipedia) did not answer. Ours to retry, not the town's fault. */
final class TownSourceUnavailable extends \RuntimeException
{
}
