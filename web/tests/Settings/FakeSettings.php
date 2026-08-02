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
    /** @param array<string, int|string> $values */
    public function __construct(private array $values = [])
    {
    }

    #[\Override]
    public function get(string $key): int
    {
        $value = $this->seeded($key);

        return \is_int($value) ? $value : throw new \InvalidArgumentException(sprintf('"%s" is seeded as text; use getString().', $key));
    }

    #[\Override]
    public function getString(string $key): string
    {
        $value = $this->seeded($key);

        return \is_string($value) ? $value : throw new \InvalidArgumentException(sprintf('"%s" is seeded as a number; use get().', $key));
    }

    public function set(string $key, int|string $value): void
    {
        $this->values[$key] = $value;
    }

    private function seeded(string $key): int|string
    {
        return $this->values[$key]
            ?? throw new \InvalidArgumentException(sprintf('FakeSettings has no value seeded for "%s".', $key));
    }
}
