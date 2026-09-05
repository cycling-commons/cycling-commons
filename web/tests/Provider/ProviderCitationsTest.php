<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Provider;

use App\Provider\ProviderCitations;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Who to credit for a row on the map, sent with the payload instead of held
 * in a front-end constant (data-provider-hierarchy.md §7).
 */
final class ProviderCitationsTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        $this->citations()->invalidate();
    }

    public function testEveryEnabledProviderIsKeyedByItsSlug(): void
    {
        $all = $this->citations()->all();

        self::assertArrayHasKey('wallonie-pivot', $all);
        self::assertArrayHasKey('osm', $all);
        self::assertArrayHasKey('wikidata', $all);
    }

    /**
     * What a drawer line needs, and nothing a reader cannot already see: no
     * endpoint, no field map, no match radius.
     */
    public function testARowCarriesTheCitationAndNothingOperational(): void
    {
        $row = $this->citations()->all()['wallonie-pivot'];

        self::assertSame(['name', 'fullName', 'homepage', 'licence', 'attribution', 'creator'], array_keys($row));
        self::assertSame('Tourisme Wallonie', $row['name']);
        self::assertSame('Creative Commons BY 4.0', $row['licence']);
        self::assertSame('Tourisme Wallonie (CC-BY)', $row['attribution']);
    }

    private function citations(): ProviderCitations
    {
        return static::getContainer()->get(ProviderCitations::class);
    }
}
