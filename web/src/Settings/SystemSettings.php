<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Settings;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Effective setting value: stored override if still legal, else the YAML default.
 *
 * @see docs/specs/system-configuration.md §3
 *
 * @api
 */
final class SystemSettings implements SettingsProviderInterface
{
    private const string CACHE_KEY = 'system_settings.overrides';

    /** @var array<string, string>|null in-request memo of RAW stored values; null = not loaded this request */
    private ?array $memo = null;

    public function __construct(
        private readonly Connection $db,
        private readonly SettingsRegistry $registry,
        private readonly CacheInterface $cache,
    ) {
    }

    #[\Override]
    public function get(string $key): int
    {
        $value = $this->effective($key);

        return \is_int($value) ? $value : throw new \InvalidArgumentException(sprintf('"%s" is a text setting; use getString().', $key));
    }

    #[\Override]
    public function getString(string $key): string
    {
        $value = $this->effective($key);

        return \is_string($value) ? $value : throw new \InvalidArgumentException(sprintf('"%s" is a numeric setting; use get().', $key));
    }

    private function effective(string $key): int|string
    {
        $def = $this->registry->get($key);
        $raw = $this->overrides()[$key] ?? null;
        if (null === $raw) {
            return $def->default;
        }
        $stored = $def->fromStorage($raw);

        return null !== $stored && $def->accepts($stored) ? $stored : $def->default;
    }

    /**
     * Every setting's effective value, in registry order.
     *
     * @return array<string, int|string>
     */
    public function all(): array
    {
        $out = [];
        foreach ($this->registry->all() as $key => $_def) {
            $out[$key] = $this->effective($key);
        }

        return $out;
    }

    public function isOverridden(string $key): bool
    {
        $this->registry->get($key);

        return isset($this->overrides()[$key]);
    }

    public function invalidate(): void
    {
        $this->memo = null;
        $this->cache->delete(self::CACHE_KEY);
    }

    /** @return array<string, string> raw stored values only, unknown keys dropped */
    private function overrides(): array
    {
        if (null !== $this->memo) {
            return $this->memo;
        }

        $loaded = $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): array {
            $rows = $this->load();
            if (null === $rows) {
                // Table missing (fresh checkout / migration): do not cache empty as the live map.
                $item->expiresAfter(1);

                return [];
            }

            return $rows;
        });

        return $this->memo = $loaded;
    }

    /** @return array<string, string>|null null when the table does not exist yet */
    private function load(): ?array
    {
        try {
            /** @var array<string, string|int> $rows */
            $rows = $this->db->fetchAllKeyValue('SELECT setting_key, setting_value FROM system_setting');
        } catch (TableNotFoundException) {
            return null;
        }

        $out = [];
        foreach ($rows as $key => $value) {
            if ($this->registry->has((string) $key)) {
                $out[(string) $key] = (string) $value;
            }
        }

        return $out;
    }
}
