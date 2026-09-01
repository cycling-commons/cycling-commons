<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Translation\Exception;

/**
 * DeepL asked the client to slow down (HTTP 429 or 529). Distinct from
 * {@see DeepLQuotaExceededException}: the key is fine, the request rate is
 * not, and the developer's move is to wait and retry rather than check
 * their account.
 *
 * @see docs/specs/translations.md §7.1
 *
 * @api
 */
final class DeepLRateLimitedException extends \RuntimeException
{
}
