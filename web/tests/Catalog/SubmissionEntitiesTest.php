<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\Entity\ChangeHistory;
use App\Catalog\Entity\Submission;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SubmissionEntitiesTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testSubmissionPersistsAndReloads(): void
    {
        $sub = (new Submission())
            ->setType(SubmissionType::Edit)
            ->setLetter('D')
            ->setItemId(null)
            ->setUserId(1)
            ->setTitle('Repair station · Malmedy')
            ->setGeom('{"type":"Point","coordinates":[6.027,50.426]}')
            ->setCountryCode('BE')
            ->setChanges(['hours' => ['was' => '24/7', 'now' => 'closed Sundays']])
            ->setPayload(['hours' => 'closed Sundays']);
        $this->em->persist($sub);
        $this->em->flush();
        $this->em->clear();

        $found = $this->em->getRepository(Submission::class)->findOneBy(['title' => 'Repair station · Malmedy']);
        self::assertNotNull($found);
        self::assertSame(SubmissionStatus::Pending, $found->getStatus());
        self::assertSame(SubmissionType::Edit, $found->getType());
        self::assertSame('closed Sundays', $found->getChanges()['hours']['now']);
        self::assertNull($found->getDecidedAt());
    }

    public function testChangeHistoryPersists(): void
    {
        $row = (new ChangeHistory())
            ->setItemId(42)
            ->setSubmissionId(null)
            ->setField('hours')
            ->setOldValue('24/7')
            ->setNewValue('closed Sundays')
            ->setChangedBy(7);
        $this->em->persist($row);
        $this->em->flush();
        $this->em->clear();

        $rows = $this->em->getRepository(ChangeHistory::class)->findBy(['itemId' => 42]);
        self::assertCount(1, $rows);
        self::assertSame('closed Sundays', $rows[0]->getNewValue());
        self::assertNotNull($rows[0]->getChangedAt());
    }

    public function testRegionCarriesCountryCode(): void
    {
        // Migration backfills wallonia → BE when present; entity default is ''.
        $region = new \App\Catalog\Entity\Region();
        self::assertSame('', $region->getCountryCode());
        self::assertSame('BE', $region->setCountryCode('be')->getCountryCode());
    }
}
