<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Community;

use App\Community\CuratorApplicationService;
use App\Community\CuratorApplicationStatus;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * 2026-07-29-country-requests-and-curator-signup-design.md §5.2.
 */
final class CuratorApplicationTest extends KernelTestCase
{
    private function user(string $email): User
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $u = new User();
        $u->setEmail($email);
        $u->setDisplayName('Applicant '.substr(md5($email), 0, 6));
        $u->setPassword('x');
        $u->setEmailVerified(true);
        $em->persist($u);
        $em->flush();

        return $u;
    }

    private function seedCountryRegion(string $cc, string $slug): int
    {
        $db = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $db->executeStatement(
            "INSERT INTO region (slug, name, geom, area_km2, country_code, iso_code, admin_level, source, created_at, updated_at)
             VALUES (?, ?, ST_GeomFromText('POLYGON((0 0,0 1,1 1,1 0,0 0))', 4326), 1000, ?, ?, 2, 'test', NOW(), NOW())",
            [$slug, strtoupper($slug), $cc, $cc],
        );

        return (int) $db->lastInsertId('region_id_seq');
    }

    public function testSubmittingStoresACleanApplication(): void
    {
        self::bootKernel();
        $this->seedCountryRegion('PT', 'portugal-test');
        $svc = self::getContainer()->get(CuratorApplicationService::class);

        $app = $svc->submit($this->user('curator-a@example.test'), 'PT', null, null, '  I live here  ');

        self::assertSame(CuratorApplicationStatus::Pending, $app->getStatus());
        self::assertSame('I live here', $app->getAbout(), 'the note filter trimmed it');
        self::assertNull($app->getRequestedRegionId(), 'null means the whole country');
    }

    public function testASecondPendingApplicationForTheSameCountryIsRefused(): void
    {
        self::bootKernel();
        $this->seedCountryRegion('GR', 'greece-test');
        $svc = self::getContainer()->get(CuratorApplicationService::class);
        $u = $this->user('curator-dup@example.test');

        $svc->submit($u, 'GR', null, null, 'first');

        $this->expectException(\DomainException::class);
        $svc->submit($u, 'GR', null, null, 'second');
    }

    public function testACountryWithNoRegionCannotBeAppliedFor(): void
    {
        // §4: with no region there is nothing to anchor a submission to, so
        // there is no evidence path and nothing to scope a curator to.
        self::bootKernel();
        $svc = self::getContainer()->get(CuratorApplicationService::class);

        $this->expectException(\DomainException::class);
        $svc->submit($this->user('curator-nocountry@example.test'), 'MN', null, null, 'nothing here yet');
    }

    public function testALinkInTheAboutTextIsRejected(): void
    {
        self::bootKernel();
        $this->seedCountryRegion('IE', 'ireland-test');
        $svc = self::getContainer()->get(CuratorApplicationService::class);

        $this->expectException(\App\Community\InvalidNoteException::class);
        $svc->submit($this->user('curator-spam@example.test'), 'IE', null, null, 'buy at https://spam.example');
    }
}
