<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Community;

/**
 * Rejected note with a machine-readable `reason`.
 */
final class InvalidNoteException extends \RuntimeException
{
    /** @param 'link'|'length'|'control' $reason */
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
