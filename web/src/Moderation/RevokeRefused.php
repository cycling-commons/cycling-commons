<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Moderation;

/**
 * An approval that cannot be revoked, and why: the message is a
 * translation key, `fields` the values changed since the approval (for
 * `moderate.revoke.error.changed_since`).
 *
 * @see docs/specs/moderation-and-contribution.md §5.6
 *
 * @api
 */
final class RevokeRefused extends \RuntimeException
{
    /** @param list<string> $fields */
    public function __construct(string $message, public readonly array $fields = [])
    {
        parent::__construct($message);
    }
}
