<?php

// SPDX-License-Identifier: AGPL-3.0-only

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
 * @see docs/specs/system-configuration.md §4
 *
 * @api
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
     * Store an override and audit old → new.
     *
     * @return bool false when unchanged
     *
     * @throws \InvalidArgumentException
     */
    public function set(string $key, int|string $value, ?User $actor): bool
    {
        $def = $this->registry->get($key);
        if (!$def->accepts($value)) {
            throw new \InvalidArgumentException($def->isString() ? sprintf('%s is not an acceptable value for %s.', var_export($value, true), $key) : sprintf('%s must be between %d and %d, got %s.', $key, (int) $def->min, (int) $def->max, var_export($value, true)));
        }

        $old = $def->isString() ? $this->settings->getString($key) : $this->settings->get($key);
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
            ['k' => $key, 'v' => (string) $value, 't' => $this->clock->now(), 'u' => $actor?->getId()],
            ['t' => Types::DATETIME_IMMUTABLE],
        );
        $this->settings->invalidate();
        $this->adminLog->log($actor, self::ACTION_CHANGE, null, sprintf('%s: %s -> %s', $key, $old, $value));

        return true;
    }

    /**
     * Drop the override so the key follows the YAML default.
     *
     * @return bool false when nothing was stored
     *
     * @throws \InvalidArgumentException
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
