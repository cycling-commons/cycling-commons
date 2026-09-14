<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Media;

use App\Media\Message\FetchCommonsPhoto;
use App\Tests\Coverage\CoverageSchema;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The harvest admits files through the same door as the drawer
 * (CommonsPhotoAdmission::admit()), judged against the row that names them.
 *
 * Pins stand at 50.55, 5.55; 0.0036 degrees north is about 400 m.
 */
final class HarvestCommonsPhotosCommandTest extends KernelTestCase
{
    use CoverageSchema;

    private Connection $db;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = self::getContainer()->get(Connection::class);
        self::ensureCoverageSchema($this->db);
        $this->db->executeStatement("DELETE FROM commons_photo WHERE file LIKE 'Test harvest%'");
        $this->db->executeStatement(
            "INSERT INTO region (slug, name, geom, area_km2, country_code, admin_level, created_at, updated_at)
             VALUES ('test-harvest-region', 'Test harvest region',
                     ST_SetSRID(ST_MakeEnvelope(5.0, 50.0, 6.0, 51.0), 4326), 100, 'BE', 4, NOW(), NOW())
             ON CONFLICT DO NOTHING",
        );
    }

    public function testAFileDeclinedForAScenicViewIsNotQueuedAgainForIt(): void
    {
        $this->declined('Test harvest view.jpg');
        self::insertCoveragePoi($this->db, ['letter' => 'P', 'name' => 'View', 'lat' => 50.55, 'lng' => 5.55,
            'tags' => ['tourism' => 'viewpoint', 'wikimedia_commons' => 'File:Test harvest view.jpg']]);

        $tester = $this->harvest('P');

        self::assertStringContainsString('already answered', $tester->getDisplay());
        self::assertSame([], $this->fetched());
        self::assertSame('declined', $this->state('Test harvest view.jpg'));
    }

    public function testAFileDeclinedForAScenicViewIsQueuedForACastle(): void
    {
        $this->declined('Test harvest castle.jpg');
        self::insertCoveragePoi($this->db, ['letter' => 'Q', 'name' => 'Castle', 'lat' => 50.55, 'lng' => 5.55,
            'tags' => ['historic' => 'castle', 'wikimedia_commons' => 'File:Test harvest castle.jpg']]);

        $this->harvest('Q');

        $sent = $this->fetched();
        self::assertCount(1, $sent);
        self::assertSame('Q', $sent[0]->letter, 'judged against the row that named it');
        self::assertSame('pending', $this->state('Test harvest castle.jpg'));
    }

    private function harvest(string $letter): CommandTester
    {
        $app = new Application(self::$kernel);
        $tester = new CommandTester($app->find('app:commons:harvest-photos'));
        $tester->execute(['--letter' => $letter]);
        $tester->assertCommandIsSuccessful();

        return $tester;
    }

    private function declined(string $file): void
    {
        $this->db->executeStatement(
            "INSERT INTO commons_photo (file, state, failed_reason, credit, license, requested_at, camera_lat, camera_lng, camera_checked_at)
             VALUES (:f, 'declined', 'camera_far', 'Jane', 'CC BY-SA 4.0', NOW(), 50.5536, 5.55, NOW())",
            ['f' => $file],
        );
    }

    private function state(string $file): string
    {
        return (string) $this->db->fetchOne('SELECT state FROM commons_photo WHERE file = :f', ['f' => $file]);
    }

    /** @return list<FetchCommonsPhoto> */
    private function fetched(): array
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $out = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof FetchCommonsPhoto) {
                $out[] = $message;
            }
        }

        return $out;
    }
}
