<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Translation;

use App\Translation\DeepL\DeepLAvailability;
use PHPUnit\Framework\TestCase;

/**
 * The gate: dev environment AND a non-empty key, both required
 * (translations.md §7.1). Either alone must leave the feature off, because
 * the kernel environment is not a runtime toggle: nothing here may light
 * this up on staging or production.
 */
final class DeepLAvailabilityTest extends TestCase
{
    public function testOffWhenTheKeyIsEmptyEvenOnDev(): void
    {
        self::assertFalse((new DeepLAvailability('dev', ''))->isOn());
    }

    public function testOffWhenTheEnvironmentIsNotDevEvenWithAKey(): void
    {
        self::assertFalse((new DeepLAvailability('prod', 'a-key'))->isOn());
        self::assertFalse((new DeepLAvailability('test', 'a-key'))->isOn());
        self::assertFalse((new DeepLAvailability('staging', 'a-key'))->isOn());
    }

    public function testOnOnlyWhenBothHold(): void
    {
        self::assertTrue((new DeepLAvailability('dev', 'a-key'))->isOn());
    }

    public function testAWhitespaceOnlyKeyIsTreatedAsEmpty(): void
    {
        self::assertFalse((new DeepLAvailability('dev', '   '))->isOn());
    }
}
