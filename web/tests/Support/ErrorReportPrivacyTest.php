<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The privacy notice says an error report never holds an IP address, holds
 * what an ordinary form sent with its secrets hidden, and nothing from the
 * forms with really personal content (`privacy.collect_auto_errors`,
 * docs/specs/privacy-notice.md). These are the settings that make it true;
 * the scrubbing itself is pinned by SentryRequestScrubberTest and
 * SentryBeforeSendTest.
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

    public function testABodyTravelsOnlyThroughTheScrubber(): void
    {
        self::assertSame('medium', $this->options()['max_request_body_size'] ?? null, 'up to 10 KB, enough to replay a form');
        self::assertSame('App\\Support\\SentryBeforeSend', $this->options()['before_send'] ?? null, 'every event passes the scrubber');
    }
}
