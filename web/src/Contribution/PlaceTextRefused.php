<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Contribution;

/**
 * A text proposal the form sends back; the message is a translation key,
 * `params` its placeholders (the link a check refused: `%url%`).
 *
 * @api
 */
final class PlaceTextRefused extends \RuntimeException
{
    /** @param array<string, string> $params */
    public function __construct(string $message, public readonly array $params = [])
    {
        parent::__construct($message);
    }
}
