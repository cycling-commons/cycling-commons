<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media;

use App\Settings\SettingsProviderInterface;
use App\Settings\SettingsRegistry;
use Symfony\Component\Mime\Address;

/**
 * Recipients for breaker-open and illegal-content escalation alerts.
 *
 * @see docs/specs/photo-uploads.md §6c, §6d
 *
 * @api
 */
final readonly class AlertRecipients
{
    public function __construct(private SettingsProviderInterface $settings)
    {
    }

    /** @return list<Address> */
    public function all(): array
    {
        $out = [];
        foreach (explode(',', $this->settings->getString(SettingsRegistry::ALERT_EMAILS)) as $raw) {
            $address = trim($raw);
            // Skip malformed entries rather than failing the whole alert.
            if ('' !== $address && false !== filter_var($address, \FILTER_VALIDATE_EMAIL)) {
                $out[] = new Address($address);
            }
        }

        return $out;
    }
}
