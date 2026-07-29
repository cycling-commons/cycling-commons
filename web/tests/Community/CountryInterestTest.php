<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Community;

use App\Community\CountryInterestService;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * 2026-07-29-country-requests-and-curator-signup-design.md §5.1.
 */
final class CountryInterestTest extends KernelTestCase
{
    private function user(string $email): User
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $u = new User();
        $u->setEmail($email);
        $u->setDisplayName('Interest '.substr(md5($email), 0, 6));
        $u->setPassword('x');
        $u->setEmailVerified(true);
        $em->persist($u);
        $em->flush();

        return $u;
    }

    public function testRecordingTwiceUpdatesRatherThanDuplicating(): void
    {
        self::bootKernel();
        $svc = self::getContainer()->get(CountryInterestService::class);
        $u = $this->user('interest-once@example.test');

        $svc->record($u, 'ES', false, 'I ride here often');
        $second = $svc->record($u, 'ES', true, 'and I would help');

        $rows = self::getContainer()->get(EntityManagerInterface::class)
            ->getConnection()
            ->fetchAllAssociative('SELECT * FROM country_interest WHERE user_id = ?', [$u->getId()]);

        self::assertCount(1, $rows, 'one interest per person per country');
        self::assertTrue($second->isWillingToCurate(), 'the second call upgraded the willingness flag');
        self::assertSame('and I would help', $second->getNote());
    }

    public function testCountsSeparateWillingVolunteersFromPlainInterest(): void
    {
        self::bootKernel();
        $svc = self::getContainer()->get(CountryInterestService::class);
        $svc->record($this->user('c1@example.test'), 'PT', false, '');
        $svc->record($this->user('c2@example.test'), 'PT', true, '');
        $svc->record($this->user('c3@example.test'), 'PT', true, '');

        $pt = null;
        foreach ($svc->counts() as $row) {
            if ('PT' === $row['countryCode']) {
                $pt = $row;
            }
        }

        self::assertNotNull($pt, 'PT appears in the counts');
        self::assertSame(3, $pt['total']);
        self::assertSame(2, $pt['willing'], 'a country with volunteers is a different proposition');
    }

    public function testRejectsAnUnknownCountryCode(): void
    {
        self::bootKernel();
        $svc = self::getContainer()->get(CountryInterestService::class);
        $this->expectException(\InvalidArgumentException::class);
        $svc->record($this->user('bad-cc@example.test'), 'ZZ', false, '');
    }
}
