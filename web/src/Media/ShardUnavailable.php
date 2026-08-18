<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media;

/**
 * A continent resolved cleanly but has no provisioned bucket (owner
 * 2026-08-18: "storage must fail"). Deliberately NOT absorbed by writing
 * into another continent's bucket: that borrow would scatter one region's
 * photos across shards and make the eventual bucket's arrival a migration
 * instead of a provisioning action. The upload endpoint turns this into a
 * storage_unavailable refusal the rider can see.
 */
final class ShardUnavailable extends \RuntimeException
{
    public function __construct(public readonly string $shard)
    {
        parent::__construct(\sprintf('No media storage is provisioned for shard "%s".', $shard));
    }
}
