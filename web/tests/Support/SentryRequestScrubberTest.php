<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\SentryRequestScrubber;
use PHPUnit\Framework\TestCase;

/**
 * What a request may carry into an error report (docs/specs/privacy-notice.md,
 * owner 2026-10-10): the body, so a failure can be replayed locally, with
 * every secret field masked, and nothing at all from the forms that carry
 * really personal content.
 */
final class SentryRequestScrubberTest extends TestCase
{
    public function testAnOrdinaryFormKeepsItsBodyForReplay(): void
    {
        $out = SentryRequestScrubber::scrub([
            'url' => 'https://cyclingcommons.org/improve',
            'method' => 'POST',
            'data' => ['name' => 'Fontaine de la passe', 'letter' => 'B', 'lat' => '50.47', 'lng' => '5.86'],
            'query_string' => 'type=water&mode=add',
        ], 'improve');

        self::assertSame(['name' => 'Fontaine de la passe', 'letter' => 'B', 'lat' => '50.47', 'lng' => '5.86'], $out['data']);
        self::assertSame('type=water&mode=add', $out['query_string']);
    }

    public function testSecretFieldsAreMaskedWhereverTheyAre(): void
    {
        $out = SentryRequestScrubber::scrub([
            'data' => [
                'note' => 'keep',
                '_csrf_token' => 'abc',
                'form' => ['plainPassword' => ['first' => 'p1', 'second' => 'p1'], 'email' => 'x@example.test'],
                '_auth_code' => '123456',
                'api_key' => 'k',
            ],
            'query_string' => 'token=secret&page=2',
        ], 'moderate_decide');

        self::assertSame('keep', $out['data']['note']);
        self::assertSame('[Filtered]', $out['data']['_csrf_token']);
        self::assertSame('[Filtered]', $out['data']['form']['plainPassword']);
        self::assertSame('x@example.test', $out['data']['form']['email'], 'only secrets are masked on an ordinary form');
        self::assertSame('[Filtered]', $out['data']['_auth_code']);
        self::assertSame('[Filtered]', $out['data']['api_key']);
        self::assertSame('token=%5BFiltered%5D&page=2', $out['query_string']);
    }

    public function testTheSensitiveFormsSendNoBodyAndNoQuery(): void
    {
        foreach (['login.en', 'register.fr', 'reset_password_request.nl', 'settings.de', 'contact_submit.es',
            'messages_reply.en', 'moderate_message.en', 'moderate_room_post.en', 'scout_traffic_submit', 'scout_tag_submit',
            'map_my_area_set', 'join_country.en', '2fa_login_check', 'settings_delete_confirm.en', 'verify_email.en'] as $route) {
            $out = SentryRequestScrubber::scrub(['data' => ['email' => 'x@example.test', 'message' => 'hello'], 'query_string' => 'expires=1&signature=s'], $route);
            self::assertArrayNotHasKey('data', $out, $route);
            self::assertArrayNotHasKey('query_string', $out, $route);
        }
    }

    public function testTheResetLinkLosesItsToken(): void
    {
        $out = SentryRequestScrubber::scrub(['url' => 'https://cyclingcommons.org/reset-password/reset/AbC123xyz', 'method' => 'GET'], 'reset_password.en');

        self::assertSame('https://cyclingcommons.org/reset-password/reset/[Filtered]', $out['url']);
    }

    public function testAStringBodyFromAnOrdinaryFormIsMaskedAsJson(): void
    {
        $out = SentryRequestScrubber::scrub(['data' => '{"lat":50.1,"password":"x"}'], 'map_ride_check');

        self::assertSame(['lat' => 50.1, 'password' => '[Filtered]'], $out['data']);
    }

    public function testNoHeaderCarriesTheVisitorsAddress(): void
    {
        // The SDK strips X-Forwarded-For and X-Real-IP itself; the rest of the
        // proxy headers that can name the visitor's address go here.
        $out = SentryRequestScrubber::scrub(['headers' => [
            'Forwarded' => 'for=203.0.113.7', 'X-Forwarded-Host' => 'cyclingcommons.org', 'CF-Connecting-IP' => '203.0.113.7',
            'True-Client-IP' => '203.0.113.7', 'X-Client-IP' => '203.0.113.7', 'X-Cluster-Client-IP' => '203.0.113.7',
            'Client-IP' => '203.0.113.7', 'User-Agent' => 'curl/8', 'Accept' => 'application/json',
        ]], 'improve');

        self::assertSame(['User-Agent' => 'curl/8', 'Accept' => 'application/json'], $out['headers']);
    }

    public function testNoRouteMeansNoBody(): void
    {
        // A request that never reached routing (a 404 a scanner sent) has no
        // route to judge it by, so nothing of its body travels.
        $out = SentryRequestScrubber::scrub(['data' => ['x' => 'y'], 'query_string' => 'a=b'], null);

        self::assertArrayNotHasKey('data', $out);
        self::assertArrayNotHasKey('query_string', $out);
    }
}
