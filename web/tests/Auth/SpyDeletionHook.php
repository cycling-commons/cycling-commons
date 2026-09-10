<?php

// SPDX-License-Identifier: AGPL-3.0-only

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

    /** When true, preDelete throws — lets tests assert deletion rolls back atomically. */
    public static bool $throwOnPreDelete = false;

    public static function reset(): void
    {
        self::$callCount = 0;
        self::$throwOnPreDelete = false;
    }

    public function preDelete(User $user): void
    {
        ++self::$callCount;
        if (self::$throwOnPreDelete) {
            throw new \RuntimeException('spy hook failure');
        }
    }
}
