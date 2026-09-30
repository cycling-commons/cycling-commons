<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Smoke;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The phone menu carries six pages the header bar has no room for
 * (site-directory.md §5, owner 2026-09-30). They are in the header markup
 * with `menu-only`, which atlas.css hides in the bar and nav.js copies into
 * the drawer with everything else.
 */
final class PhoneMenuLinksTest extends WebTestCase
{
    public function testTheHeaderCarriesTheMenuOnlyPagesInOrder(): void
    {
        $client = static::createClient();
        foreach (['/about' => '', '/nl/over-ons' => '/nl'] as $path => $prefix) {
            $crawler = $client->request('GET', $path);
            if ($client->getResponse()->isRedirect()) {
                $crawler = $client->followRedirect();
            }
            self::assertResponseIsSuccessful();

            $hrefs = $crawler->filter('.topnav .links a.menu-only')->each(static fn ($a): string => (string) $a->attr('href'));
            self::assertCount(6, $hrefs, $path);
            foreach ($hrefs as $href) {
                self::assertStringStartsWith($prefix.'/', $href, 'menu links follow the page language');
            }
        }
    }

    public function testTheBarHidesThemAndOnlyThem(): void
    {
        $css = (string) file_get_contents(\dirname(__DIR__, 2).'/assets/styles/atlas.css');
        self::assertStringContainsString('.topnav .links a.menu-only{display:none}', $css);
        // The drawer is appended to <body>, outside .topnav, so the rule never reaches the copies.
        $js = (string) file_get_contents(\dirname(__DIR__, 2).'/assets/js/nav.js');
        self::assertStringContainsString('document.body.appendChild(drawer)', $js);
    }
}
