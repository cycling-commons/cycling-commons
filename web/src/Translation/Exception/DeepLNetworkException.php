<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Translation\Exception;

/**
 * DeepL could not be reached at all: a timeout, a DNS failure, a dropped
 * connection. Distinct from a rejected request, whose response DeepL did
 * send.
 *
 * @see docs/specs/translations.md §7.1
 *
 * @api
 */
final class DeepLNetworkException extends \RuntimeException
{
}
