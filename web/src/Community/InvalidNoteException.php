<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Community;

/**
 * Why a rejected note carries a machine-readable reason: the form shows the
 * rider a specific message, and "no links here" is the one rejection a
 * legitimate person actually hits.
 */
final class InvalidNoteException extends \RuntimeException
{
    /** @param 'link'|'length'|'control' $reason */
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
