<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Onboarding;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class RestampRegionsCommandTest extends KernelTestCase
{
    private function db(): Connection
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    /** @param array<string, mixed> $options */
    private function run_(array $options): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:regions:restamp'));
        $tester->execute($options);

        return $tester;
    }

    /** @return array{region: int, submission: int, item: int} */
    private function seed(): array
    {
        $db = $this->db();
        $region = (int) $db->fetchOne(
            "INSERT INTO region (slug, name, geom, area_km2, country_code, admin_level, created_at, updated_at)
             VALUES ('rst-xa', 'Restamp XA', ST_Multi(ST_GeomFromText('POLYGON((40 40,41 40,41 41,40 41,40 40))', 4326)), 9000, 'XA', 4, NOW(), NOW())
             RETURNING id",
        );
        $user = (new User())->setEmail('restamp-'.uniqid('', true).'@test.test');
        $user->setPassword('x');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($user);
        $em->flush();
        $submission = (int) $db->fetchOne(
            "INSERT INTO submission (type, letter, user_id, status, title, geom, country_code, changes, payload, created_at)
             VALUES ('new', 'B', :u, 'pending', 'Tap', ST_SetSRID(ST_MakePoint(40.5, 40.5), 4326), '', '{}', '{}', NOW()) RETURNING id",
            ['u' => (int) $user->getId()],
        );
        $item = (int) $db->fetchOne(
            "INSERT INTO item (letter, name, geom, country_code, state, source, source_ref, attributes, created_at, updated_at)
             VALUES ('B', 'Tap', ST_SetSRID(ST_MakePoint(40.5, 40.5), 4326), 'BE', 'unverified', 'user', 'restamp:tap', '{}', NOW(), NOW()) RETURNING id",
        );

        return ['region' => $region, 'submission' => $submission, 'item' => $item];
    }

    public function testANullRegionSubmissionAndAWrongItemCountryAreRepaired(): void
    {
        self::bootKernel();
        $ids = $this->seed();

        $tester = $this->run_(['--countries' => 'xa']);
        $tester->assertCommandIsSuccessful();

        self::assertSame(
            ['region_id' => $ids['region'], 'country_code' => 'XA'],
            array_map(static fn ($v) => \is_numeric($v) ? (int) $v : $v, (array) $this->db()->fetchAssociative('SELECT region_id, country_code FROM submission WHERE id = ?', [$ids['submission']])),
        );
        self::assertSame('XA', $this->db()->fetchOne('SELECT country_code FROM item WHERE id = ?', [$ids['item']]));
        self::assertMatchesRegularExpression('/submission\s+XA\s+1/', $tester->getDisplay());
        self::assertMatchesRegularExpression('/item\s+XA\s+1/', $tester->getDisplay());
    }

    public function testADryRunCountsAndWritesNothing(): void
    {
        self::bootKernel();
        $ids = $this->seed();

        $tester = $this->run_(['--countries' => 'XA', '--dry-run' => true]);
        $tester->assertCommandIsSuccessful();

        self::assertNull($this->db()->fetchOne('SELECT region_id FROM submission WHERE id = ?', [$ids['submission']]));
        self::assertSame('BE', $this->db()->fetchOne('SELECT country_code FROM item WHERE id = ?', [$ids['item']]));
        self::assertMatchesRegularExpression('/submission\s+XA\s+1/', $tester->getDisplay());
        self::assertStringContainsString('dry run', $tester->getDisplay());
    }

    public function testAnUnlistedCountryIsLeftAlone(): void
    {
        self::bootKernel();
        $ids = $this->seed();

        $this->run_(['--countries' => 'XB'])->assertCommandIsSuccessful();

        self::assertSame('BE', $this->db()->fetchOne('SELECT country_code FROM item WHERE id = ?', [$ids['item']]));
        self::assertNull($this->db()->fetchOne('SELECT region_id FROM submission WHERE id = ?', [$ids['submission']]));
    }

    public function testABadCountryListIsRefused(): void
    {
        self::bootKernel();
        self::assertSame(2, $this->run_(['--countries' => 'X1,'])->getStatusCode());
    }
}
