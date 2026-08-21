<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media;

/**
 * Resolved continent with no provisioned bucket — refuse, never borrow.
 *
 * @see docs/specs/media-storage-architecture.md §2.1
 */
final class ShardUnavailable extends \RuntimeException
{
    public function __construct(string $shard)
    {
        parent::__construct(\sprintf('No media storage is provisioned for shard "%s".', $shard));
    }
}
