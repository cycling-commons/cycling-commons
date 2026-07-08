<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Moderation;

/** Approving a route into a region already at its active cap (spec §6, D8). */
final class RegionFullException extends \RuntimeException
{
}
