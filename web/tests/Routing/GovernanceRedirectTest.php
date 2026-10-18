<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Routing;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** /governance on the site, in every language, leads to the governance page on the wiki. */
final class GovernanceRedirectTest extends WebTestCase
{
    public function testGovernanceLeadsToTheWiki(): void
    {
        $client = static::createClient();
        foreach (['/governance', '/fr/governance', '/nl/governance', '/de/governance', '/es/governance'] as $path) {
            $client->request('GET', $path);
            self::assertResponseRedirects('https://wiki.cyclingcommons.org/governance/', 301, $path);
        }
    }
}
