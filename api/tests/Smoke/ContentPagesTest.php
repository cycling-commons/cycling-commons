<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Tests\Smoke;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ContentPagesTest extends WebTestCase
{
    public function testAboutRenders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/about');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('nav.topnav .links');
        self::assertSelectorExists('footer.foot');
        self::assertSelectorTextContains('h1.disp', 'The map belongs to everyone');
    }
}
