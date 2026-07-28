<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Settings;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Reads the effective value of every runtime setting: the admin's override
 * from `system_setting` when there is a usable one, the YAML default otherwise.
 *
 * ## Caching
 *
 * CuratedReadiness reads three settings on every curator-desk render, so the
 * whole override map lives in ONE cache item rather than a row read per key,
 * plus an in-request memo so a single render never touches the pool twice.
 * SystemSettingsWriter calls invalidate() after every write; nothing else may
 * change the table, so there is no other staleness path.
 *
 * The table holds deviations only — a key with no row is at its default. Note
 * that a value an admin explicitly sets IS stored even when it equals the
 * current default: that pins the choice, so a later release that moves the YAML
 * default cannot silently move a number somebody deliberately chose.
 *
 * @see docs/specs/system-configuration.md §3
 *
 * @api Autowired as SettingsProviderInterface into every threshold consumer.
 */
final class SystemSettings implements SettingsProviderInterface
{
    private const string CACHE_KEY = 'system_settings.overrides';

    /** @var array<string, int>|null in-request memo; null = not loaded this request */
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
        $def = $this->registry->get($key);
        $stored = $this->overrides()[$key] ?? null;

        return null !== $stored && $def->accepts($stored) ? $stored : $def->default;
    }

    /**
     * Every setting's effective value, in registry order.
     *
     * @return array<string, int>
     */
    public function all(): array
    {
        $out = [];
        foreach ($this->registry->all() as $key => $_def) {
            $out[$key] = $this->get($key);
        }

        return $out;
    }

    /** Whether an admin has explicitly set this key (i.e. a row exists to reset). */
    public function isOverridden(string $key): bool
    {
        $this->registry->get($key); // reject unknown keys here too

        return isset($this->overrides()[$key]);
    }

    /**
     * Drops the cached override map. Called by SystemSettingsWriter after every
     * write; there is no other writer, so nothing else needs to call it.
     */
    public function invalidate(): void
    {
        $this->memo = null;
        $this->cache->delete(self::CACHE_KEY);
    }

    /** @return array<string, int> stored values only, unknown keys dropped */
    private function overrides(): array
    {
        if (null !== $this->memo) {
            return $this->memo;
        }

        $loaded = $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): array {
            $rows = $this->load();
            if (null === $rows) {
                // No table yet — a fresh checkout warming caches, or the
                // migration run itself booting the kernel. Answer with the
                // defaults, but do not pin that answer into the cache, or the
                // first post-migration request would still see an empty map.
                $item->expiresAfter(1);

                return [];
            }

            return $rows;
        });

        return $this->memo = $loaded;
    }

    /** @return array<string, int>|null null when the table does not exist yet */
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
            // A row for a key the registry no longer defines (a setting removed
            // in a later release) is inert rather than fatal.
            if ($this->registry->has((string) $key)) {
                $out[(string) $key] = (int) $value;
            }
        }

        return $out;
    }
}
