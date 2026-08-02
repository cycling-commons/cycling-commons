<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media;

use App\Settings\SettingsProviderInterface;
use App\Settings\SettingsRegistry;
use Symfony\Component\Mime\Address;

/**
 * Who gets told when something operational happens: the auto-withhold circuit
 * breaker opening (docs/specs/photo-uploads.md §6c) and every escalation of
 * suspected illegal content (§6d).
 *
 * Runtime-editable rather than env-backed for one reason: the person who reads
 * that mailbox goes on holiday. An escalation cannot wait for them to come
 * back, and cover should not need a deploy. The list is validated where it is
 * defined (SettingsRegistry) so an unparseable or empty list can never be
 * saved — an alert nobody receives is worse than a noisy one.
 *
 * @api Injected by every alerting service.
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
            // Belt and braces against a value stored before the validator, or
            // by hand: a malformed entry is skipped rather than allowed to
            // throw and take the whole alert with it.
            if ('' !== $address && false !== filter_var($address, \FILTER_VALIDATE_EMAIL)) {
                $out[] = new Address($address);
            }
        }

        return $out;
    }
}
