<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Tests\Auth;

use App\Entity\User;
use App\Service\UserDeletionHookInterface;

/**
 * Test spy for UserDeletionHookInterface.
 * Registered as a tagged service in config/packages/test/services.yaml.
 * Uses a static counter so tests can detect invocations across DI boundaries.
 */
final class SpyDeletionHook implements UserDeletionHookInterface
{
    public static int $callCount = 0;

    public function preDelete(User $user): void
    {
        ++self::$callCount;
    }
}
