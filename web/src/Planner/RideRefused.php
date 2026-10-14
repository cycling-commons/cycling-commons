<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Planner;

/**
 * A save the planner cannot take as sent, with the reason as a machine code
 * the endpoint answers and the page explains.
 *
 * @api
 */
final class RideRefused extends \RuntimeException
{
    /** The line is empty or longer than the route domain's 400 km cap. */
    public const string LENGTH_RANGE = 'length_range';

    /** The claimed distance is more than five percent off the line's measured length. */
    public const string LENGTH_MISMATCH = 'length_mismatch';

    /** The id names no ride of this rider; another rider's ride is a missing ride. */
    public const string NOT_FOUND = 'not_found';

    /** The rider already holds the most rides an account may. */
    public const string TOO_MANY_RIDES = 'too_many_rides';

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
