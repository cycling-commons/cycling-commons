<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Catalog\Entity\Region;
use App\Tests\Coverage\CoverageSchema;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CoveragePageTest extends WebTestCase
{
    use CoverageSchema;

    private const GEOM = '{"type":"MultiPolygon","coordinates":[[[[4,50],[5,50],[5,51],[4,51],[4,50]]]]}';

    private function seedRegion(string $slug, string $cc, ?int $level): int
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $r = (new Region())->setSlug($slug)->setName(ucfirst($slug))
            ->setCountryCode($cc)->setAdminLevel($level)->setGeom(self::GEOM)->setAreaKm2(1000.0);
        $em->persist($r);
        $em->flush();

        return (int) $r->getId();
    }

    public function testCoveragePageRendersRealCountsAndOnlyOperationalCountries(): void
    {
        $client = static::createClient();
        $db = static::getContainer()->get(Connection::class);
        $rid = $this->seedRegion('wallonia-cov', 'BE', 4);
        $this->seedRegion('belgium-cov', 'BE', 2);   // L2 infrastructure row — must not count as a region

        $db->executeStatement(
            "INSERT INTO item (letter, name, geom, country_code, region_id, state, source, source_ref, attributes, created_at, updated_at)
             VALUES ('B', 'Cov Climb', ST_SetSRID(ST_GeomFromText('POINT(4.5 50.5)'), 4326), 'BE', :r, 'verified', 'seed', :ref, '{}', NOW(), NOW())",
            ['r' => $rid, 'ref' => 'cov-'.uniqid()],
        );
        self::ensureCoverageSchema($db);
        self::insertCoveragePoi($db, ['country_code' => 'BE', 'region_id' => $rid]);

        $client->request('GET', '/coverage');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('Belgium', $html);
        self::assertStringNotContainsString('France', $html, 'non-onboarded countries no longer render');
        self::assertStringNotContainsString('312k', $html, 'the demo KPIs are gone');
        // The BE row: exactly one operational region despite the L2 row existing.
        self::assertMatchesRegularExpression('/<td class="mono">1<\/td>/', $html, 'region count column');
    }

    public function testThinnestCategoriesComeFromRealCounts(): void
    {
        $client = static::createClient();
        $this->seedRegion('wallonia-cov2', 'BE', 4);

        $client->request('GET', '/coverage');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        // With an empty catalog every category counts 0 — the gap cards must
        // still render three real category labels, not the demo's invented ones.
        self::assertStringNotContainsString('Stays in France', $html);
        self::assertSame(3, substr_count($html, 'class="card"'), 'three data-driven gap cards');
    }
}
