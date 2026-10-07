<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Traffic;

/**
 * A traffic request refused whole (422): a track-shaped key (`track_not_accepted`)
 * or a malformed shape (`invalid`). The message is the response's error code.
 *
 * @see docs/specs/traffic-measurements.md §4.1
 *
 * @api
 */
final class TrafficPayloadRefused extends \RuntimeException
{
}
