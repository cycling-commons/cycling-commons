<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Smoke;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MapFieldSchemaTest extends WebTestCase
{
    public function testMapPageEmitsFieldSchemaGlobal(): void
    {
        $client = static::createClient();
        $client->request('GET', '/map');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('window.CC_FIELD_SCHEMA', $html);
        // A known BikeServices (D) display label proves the registry reached the page.
        self::assertStringContainsString('Tools available', $html);
    }
}
