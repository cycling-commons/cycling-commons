<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The error tracker receives a request body only through the scrubber (owner
 * 2026-10-10: a body lets a failure be replayed locally): up to 10 KB, secrets
 * masked, and nothing from the forms with personal content, such as sign-in,
 * the account, messages and Scout's traffic summaries and tags
 * (App\Support\SentryRequestScrubber, pinned by SentryRequestScrubberTest).
 */
final class ErrorTrackerBodyTest extends TestCase
{
    public function testABodyOnlyEverTravelsThroughTheScrubber(): void
    {
        $config = Yaml::parseFile(\dirname(__DIR__, 2).'/config/packages/sentry.yaml');

        self::assertSame('medium', $config['sentry']['options']['max_request_body_size'] ?? null);
        self::assertSame('App\\Support\\SentryBeforeSend', $config['sentry']['options']['before_send'] ?? null);
        self::assertFalse($config['sentry']['options']['send_default_pii']);
        self::assertContains('scout_traffic_submit', \App\Support\SentryRequestScrubber::PERSONAL_ROUTES, 'traffic summaries never travel');
        self::assertContains('scout_tag_submit', \App\Support\SentryRequestScrubber::PERSONAL_ROUTES, 'tag positions never travel');
    }
}
