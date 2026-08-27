<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Support;

use App\Settings\SettingsProviderInterface;
use App\Settings\SettingsRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Address;

/**
 * Who gets told when somebody writes in or files a bug.
 *
 * A separate list from {@see \App\Media\AlertRecipients}, on purpose. That one
 * wakes people up: a flood of intimate-imagery reports has tripped the breaker
 * and somebody must act now. This one is the front door, and the two must be
 * able to point at different people. Otherwise either the urgent list drowns
 * in "how do I add a water tap", or the support list is a pager.
 *
 * **Three steps, so a fresh deployment cannot silently swallow the first message
 * it ever receives.** The support list, then the security alert list, then the
 * address published on the contact page itself. That last step is the one that
 * matters in practice: a deployment that has filled in its legal identity block
 * has, by definition, published an address it reads. Dropping a message on the
 * floor because a second list was never configured is the failure mode this
 * whole feature exists to end.
 *
 * Editable at runtime from /admin/system-config, like every other operational
 * address (system-configuration.md §2).
 *
 * @see docs/specs/contact-and-support.md §7
 *
 * @api
 */
final readonly class SupportRecipients
{
    public function __construct(
        private SettingsProviderInterface $settings,
        #[Autowire('%cc.support.public_email%')]
        private string $publicEmail,
    ) {
    }

    /** @return list<Address> */
    public function all(): array
    {
        foreach ([
            $this->settings->getString(SettingsRegistry::SUPPORT_EMAILS),
            $this->settings->getString(SettingsRegistry::ALERT_EMAILS),
            $this->publicEmail,
        ] as $candidate) {
            $addresses = $this->parse($candidate);
            if ([] !== $addresses) {
                return $addresses;
            }
        }

        return [];
    }

    /** @return list<Address> */
    private function parse(string $raw): array
    {
        $out = [];
        foreach (explode(',', $raw) as $part) {
            $address = trim($part);
            // Skip a malformed entry rather than failing the whole send: one
            // typo in a comma-separated list must not stop the other people
            // being told that somebody is waiting for an answer.
            if ('' !== $address && false !== filter_var($address, \FILTER_VALIDATE_EMAIL)) {
                $out[] = new Address($address);
            }
        }

        return $out;
    }
}
