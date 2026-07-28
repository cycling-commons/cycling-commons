<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Settings;

use App\Settings\SettingsProviderInterface;

/**
 * A settings provider backed by a plain array, for unit tests that construct a
 * consumer directly instead of pulling it out of the container.
 *
 * It deliberately throws on an unseeded key rather than returning a zero: a
 * test that forgets to seed a threshold must fail loudly, not quietly assert
 * against a gate that is wide open.
 */
final class FakeSettings implements SettingsProviderInterface
{
    /** @param array<string, int> $values */
    public function __construct(private array $values = [])
    {
    }

    #[\Override]
    public function get(string $key): int
    {
        return $this->values[$key]
            ?? throw new \InvalidArgumentException(sprintf('FakeSettings has no value seeded for "%s".', $key));
    }

    public function set(string $key, int $value): void
    {
        $this->values[$key] = $value;
    }
}
