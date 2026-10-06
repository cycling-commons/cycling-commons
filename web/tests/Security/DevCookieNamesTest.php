<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The dev stack names its login cookies apart (owner, 2026-10-06): a browser
 * keeps cookies per host, not per port, so on localhost another local app's
 * PHPSESSID or REMEMBERME replaced ours and signed the rider out. Production
 * keeps the names the privacy notice lists. The dev names still contain
 * SESSION and REMEMBERME, the words the page cache bypass looks for
 * (docs/specs/page-caching.md).
 */
final class DevCookieNamesTest extends TestCase
{
    private static function yaml(string $file): array
    {
        return Yaml::parseFile(\dirname(__DIR__, 2).'/config/'.$file, Yaml::PARSE_CUSTOM_TAGS);
    }

    public function testProductionKeepsTheNamesThePrivacyNoticeLists(): void
    {
        $params = self::yaml('services.yaml')['parameters'];
        self::assertSame('PHPSESSID', $params['cc.session_cookie']);
        self::assertSame('REMEMBERME', $params['cc.remember_me_cookie']);
    }

    public function testTheDevStackNamesItsOwnCookiesTheCacheStillRecognises(): void
    {
        $dev = self::yaml('services.yaml')['when@dev']['parameters'];
        self::assertSame('CC_DEV_SESSION', $dev['cc.session_cookie']);
        self::assertSame('CC_DEV_REMEMBERME', $dev['cc.remember_me_cookie']);
        self::assertMatchesRegularExpression('/SESSION|REMEMBERME/i', $dev['cc.session_cookie']);
        self::assertMatchesRegularExpression('/REMEMBERME/i', $dev['cc.remember_me_cookie']);
    }

    public function testBothCookiesTakeTheirNameFromThoseParameters(): void
    {
        self::assertSame('%cc.session_cookie%', self::yaml('packages/framework.yaml')['framework']['session']['name']);
        self::assertSame('%cc.remember_me_cookie%', self::yaml('packages/security.yaml')['security']['firewalls']['main']['remember_me']['name']);
    }
}
