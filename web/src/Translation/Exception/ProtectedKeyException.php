<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Translation\Exception;

/**
 * The key is a consent contract and is not translatable in-site.
 *
 * @see \App\Translation\ProtectedKeys
 *
 * @api
 */
final class ProtectedKeyException extends \RuntimeException
{
}
