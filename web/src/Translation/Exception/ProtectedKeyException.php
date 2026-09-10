<?php

// SPDX-License-Identifier: AGPL-3.0-only

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
