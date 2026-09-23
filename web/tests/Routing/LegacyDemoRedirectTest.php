<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Routing;

use App\Controller\LegacyDemoRedirectController;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Inbound links to the retired prototype keep working after launch. */
final class LegacyDemoRedirectTest extends WebTestCase
{
    public function testEveryDemoPageLandsOnALivePage(): void
    {
        $client = static::createClient();
        foreach (LegacyDemoRedirectController::PAGES as $page) {
            // security.yaml's `^(/[a-z]{2})?/moderate` access_control also matches
            // `/moderate.html`, so an anonymous visitor is gated to /login before our
            // route ever runs; covered separately below instead of asserting 301 here.
            if ('moderate' === $page) {
                continue;
            }

            $client->request('GET', '/'.$page.'.html');
            self::assertResponseStatusCodeSame(301, $page);
            self::assertResponseRedirects('/'.$page, 301);

            $client->request('GET', '/'.$page);
            self::assertNotSame(404, $client->getResponse()->getStatusCode(), '/'.$page.' is gone');
            self::assertLessThan(500, $client->getResponse()->getStatusCode(), '/'.$page);
        }
    }

    /** ROLE_CURATOR gates /moderate ahead of routing; an anonymous hit lands on a live login, never a 404. */
    public function testModerateHtmlIsGatedBeforeTheRedirectFires(): void
    {
        $client = static::createClient();
        $client->request('GET', '/moderate.html');
        self::assertResponseRedirects('/login', 302);
    }

    public function testIndexGoesHome(): void
    {
        $client = static::createClient();
        $client->request('GET', '/index.html');
        self::assertResponseRedirects('/', 301);
    }

    public function testQueryStringSurvives(): void
    {
        $client = static::createClient();
        $client->request('GET', '/map.html?lat=50.85&lon=4.35');
        self::assertResponseRedirects('/map?lat=50.85&lon=4.35', 301);
    }

    public function testUnknownHtmlIsStillA404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/wp-login.html');
        self::assertResponseStatusCodeSame(404);
    }

    public function testPatternMatchesTheList(): void
    {
        $r = new \ReflectionClassConstant(LegacyDemoRedirectController::class, 'PAGE_PATTERN');
        self::assertSame(implode('|', LegacyDemoRedirectController::PAGES), $r->getValue());
    }
}
