<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Contribution;

/**
 * A text proposal the form sends back; the message is a translation key.
 *
 * @api
 */
final class PlaceTextRefused extends \RuntimeException
{
}
