<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Settings;

use App\Entity\User;
use App\Service\AdminActionLogger;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Clock\ClockInterface;

/**
 * The only write path into `system_setting`.
 *
 * It is a separate class from SystemSettings on purpose: the reader is on the
 * hot path (three reads per curator-desk render) and must stay a Connection
 * plus a cache, while every write has to validate against the registry,
 * invalidate the cache and land in the admin audit log. Keeping the audit
 * logger out of the reader also keeps the entity manager off that path.
 *
 * Validation lives here rather than in the controller so no future caller —
 * a console command, a fixture, a second admin surface — can write a value the
 * admin page would refuse.
 *
 * @see docs/specs/system-configuration.md §4
 *
 * @api Called by the admin system-config page.
 */
final class SystemSettingsWriter
{
    /** Audit actions; AdminActionLog::$action is a 40-char column. */
    public const string ACTION_CHANGE = 'system_setting.change';
    public const string ACTION_RESET = 'system_setting.reset';

    public function __construct(
        private readonly Connection $db,
        private readonly SettingsRegistry $registry,
        private readonly SystemSettings $settings,
        private readonly AdminActionLogger $adminLog,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Stores an override and records old -> new in the audit log.
     *
     * @return bool false when the value was already stored and unchanged, so
     *              re-saving an untouched form does not fill the log with noise
     *
     * @throws \InvalidArgumentException on an unknown key or an out-of-range value
     */
    public function set(string $key, int $value, ?User $actor): bool
    {
        $def = $this->registry->get($key);
        if (!$def->accepts($value)) {
            throw new \InvalidArgumentException(sprintf('%s must be between %d and %d, got %d.', $key, $def->min, $def->max, $value));
        }

        $old = $this->settings->get($key);
        if ($old === $value && $this->settings->isOverridden($key)) {
            return false;
        }

        $this->db->executeStatement(
            'INSERT INTO system_setting (setting_key, setting_value, updated_at, updated_by_id)
                  VALUES (:k, :v, :t, :u)
             ON CONFLICT (setting_key) DO UPDATE
                    SET setting_value = EXCLUDED.setting_value,
                        updated_at    = EXCLUDED.updated_at,
                        updated_by_id = EXCLUDED.updated_by_id',
            ['k' => $key, 'v' => $value, 't' => $this->clock->now(), 'u' => $actor?->getId()],
            ['t' => Types::DATETIME_IMMUTABLE],
        );
        $this->settings->invalidate();
        $this->adminLog->log($actor, self::ACTION_CHANGE, null, sprintf('%s: %d -> %d', $key, $old, $value));

        return true;
    }

    /**
     * Drops the override so the key follows the YAML default again.
     *
     * @return bool false when there was nothing stored to drop
     *
     * @throws \InvalidArgumentException on an unknown key
     */
    public function reset(string $key, ?User $actor): bool
    {
        $def = $this->registry->get($key);
        if (!$this->settings->isOverridden($key)) {
            return false;
        }

        $old = $this->settings->get($key);
        $this->db->executeStatement('DELETE FROM system_setting WHERE setting_key = :k', ['k' => $key]);
        $this->settings->invalidate();
        $this->adminLog->log(
            $actor,
            self::ACTION_RESET,
            null,
            sprintf('%s: %d -> %d (default)', $key, $old, $def->default)
        );

        return true;
    }
}
