<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Translation\Exception;

/**
 * DeepL answered with something other than a translation: an unexpected
 * status code, or a 200 whose body does not carry the shape this client
 * asked for. The catch-all for a failure that is neither the key, the
 * quota, the rate limit, nor the network, so it still surfaces rather than
 * being folded into one of those and misdiagnosed.
 *
 * @see docs/specs/translations.md §7.1
 *
 * @api
 */
final class DeepLRequestException extends \RuntimeException
{
}
