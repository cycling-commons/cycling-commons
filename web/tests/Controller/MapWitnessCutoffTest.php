<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Catalog\ConfirmationFreshness;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The map shell hands the coverage layer one date: a tile point whose
 * `check_date` is on or after it has a witness inside the window and drops
 * the "?" (data-provider-hierarchy.md §6.7.7, rung 8). The window is
 * map.confirmation_stale_months, the same one every other freshness rule
 * reads, so the tiles and the pins age by one clock.
 */
final class MapWitnessCutoffTest extends WebTestCase
{
    public function testTheShellCarriesTheWitnessCutoffAsAnIsoDate(): void
    {
        $client = static::createClient();
        $client->request('GET', '/map');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertMatchesRegularExpression('/window\.CC_WITNESS_CUTOFF = "\d{4}-\d{2}-\d{2}";/', $html);

        $expected = static::getContainer()->get(ConfirmationFreshness::class)
            ->staleBefore(new \DateTimeImmutable())->format('Y-m-d');
        self::assertStringContainsString('window.CC_WITNESS_CUTOFF = "'.$expected.'";', $html);
    }
}
