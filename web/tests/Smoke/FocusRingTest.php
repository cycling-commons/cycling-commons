<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Smoke;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * /accessibility promises the focus outline is never removed outside the
 * map. Page stylesheets set outline:none on their fields, so atlas.css puts a
 * ring back with !important (contact-and-support.md, "Every text field ...
 * keeps a focus ring"). The map page does not load atlas.css.
 */
final class FocusRingTest extends WebTestCase
{
    public function testEveryFieldKeepsAFocusRingOutsideTheMap(): void
    {
        $css = (string) file_get_contents(\dirname(__DIR__, 2).'/assets/styles/atlas.css');
        self::assertStringContainsString(
            'input:not([type="checkbox"]):not([type="radio"]):focus-visible,select:focus-visible,textarea:focus-visible{outline:2px solid var(--trail)!important;outline-offset:2px}',
            $css,
        );

        $client = static::createClient();
        $client->request('GET', '/contact');
        self::assertResponseIsSuccessful();
        self::assertMatchesRegularExpression('#href="[^"]*styles/atlas[^"]*\.css"#', (string) $client->getResponse()->getContent());

        $client->request('GET', '/map');
        self::assertResponseIsSuccessful();
        self::assertDoesNotMatchRegularExpression('#href="[^"]*styles/atlas[^"]*\.css"#', (string) $client->getResponse()->getContent(), 'the map styles its own fields');
    }
}
