<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The error tracker never receives a request body. The SDK attaches bodies up
 * to 10 KB by default, whatever send_default_pii says, and bodies here carry
 * traffic summaries (roads with dates), tag positions, messages and passwords.
 * No exception needs one to be understood.
 */
final class ErrorTrackerBodyTest extends TestCase
{
    public function testRequestBodiesAreNeverSent(): void
    {
        $config = Yaml::parseFile(\dirname(__DIR__, 2).'/config/packages/sentry.yaml');

        self::assertSame('never', $config['sentry']['options']['max_request_body_size'] ?? null);
        self::assertFalse($config['sentry']['options']['send_default_pii']);
    }
}
