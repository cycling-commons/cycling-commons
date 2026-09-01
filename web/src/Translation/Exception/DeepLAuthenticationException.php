<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Translation\Exception;

/**
 * DeepL rejected the configured key, or none is configured at all.
 *
 * The developer holds the key and is the only person who can fix this, so
 * the message names the environment variable to check rather than a
 * generic "unauthorized".
 *
 * @see docs/specs/translations.md §7.1
 *
 * @api
 */
final class DeepLAuthenticationException extends \RuntimeException
{
}
