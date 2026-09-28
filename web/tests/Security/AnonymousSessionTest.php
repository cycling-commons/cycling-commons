<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * An anonymous visitor keeps no session, whatever they click.
 *
 * A session on the first request takes the page cache away from that visitor
 * for the rest of the visit (PublicPageCacheSubscriber rules out any request
 * with a session). Two clicks started one (devOps 2026-09-28): a page that
 * needs a sign-in (Symfony remembered the wanted page in a new session) and
 * the language switch.
 */
final class AnonymousSessionTest extends WebTestCase
{
    public function testAPageThatNeedsASignInSendsTheWayBackInTheLinkNotASession(): void
    {
        $client = static::createClient();
        $client->request('GET', '/vote');

        self::assertResponseRedirects('/login?_target_path=%2Fvote', 302);
        self::assertNull($client->getCookieJar()->get('PHPSESSID'), 'no session for being sent to sign in');

        $crawler = $client->request('GET', '/login?_target_path=%2Fvote');
        self::assertSame('/vote', $crawler->filter('input[name="_target_path"]')->attr('value'), 'the form carries the way back');
    }

    /** Only a path on this site travels: never another host, never `//host`, which a browser reads as one. */
    public function testTheWayBackIsALocalPathOnly(): void
    {
        $client = static::createClient();
        foreach (['/\\evil.example', 'javascript:alert(1)', 'evil.example'] as $target) {
            $crawler = $client->request('GET', '/login?_target_path='.rawurlencode($target));
            self::assertCount(0, $crawler->filter('input[name="_target_path"]'), $target);
        }

        // A URL with a host keeps its path and loses the host: /x on this site.
        foreach (['https://evil.example/x', '//evil.example/x'] as $target) {
            $crawler = $client->request('GET', '/login?_target_path='.rawurlencode($target));
            self::assertSame('/x', $crawler->filter('input[name="_target_path"]')->attr('value'), $target);
        }
    }

    public function testTheLanguageSwitchStartsNoSession(): void
    {
        $client = static::createClient();
        $client->request('GET', '/i18n/nl?to=/nl/beste');

        self::assertResponseRedirects('/nl/beste', 302);
        self::assertNull($client->getCookieJar()->get('PHPSESSID'));
    }
}
