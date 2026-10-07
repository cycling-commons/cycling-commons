<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The privacy notice says an error report never holds an IP address, a name,
 * an email address or what was typed into a form (`privacy.collect_auto_errors`,
 * docs/specs/privacy-notice.md). These are the settings that make it true.
 */
final class ErrorReportPrivacyTest extends TestCase
{
    /** @return array<string, mixed> */
    private function options(): array
    {
        $config = Yaml::parseFile(\dirname(__DIR__, 2).'/config/packages/sentry.yaml');
        \assert(\is_array($config) && \is_array($config['sentry']['options'] ?? null));

        /* @var array<string, mixed> */
        return $config['sentry']['options'];
    }

    public function testAnErrorReportCarriesNoIpAddressAndNoUser(): void
    {
        self::assertFalse($this->options()['send_default_pii'] ?? null);
    }

    public function testAnErrorReportCarriesNoFormContents(): void
    {
        self::assertSame('never', $this->options()['max_request_body_size'] ?? null);
    }
}
