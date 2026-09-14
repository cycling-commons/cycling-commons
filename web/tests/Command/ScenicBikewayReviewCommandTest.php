<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A scenic item stays only within the rule's range of a bike way
 * (docs/specs/scenic-views.md). The pipeline measures; this retires what
 * nobody has touched and lists the rest for a person.
 */
final class ScenicBikewayReviewCommandTest extends KernelTestCase
{
    private Connection $db;
    private string $dir;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = static::getContainer()->get(Connection::class);
        $this->dir = (string) tempnam(sys_get_temp_dir(), 'scenicbw');
        unlink($this->dir);
        mkdir($this->dir);
    }

    #[\Override]
    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
        parent::tearDown();
    }

    private function item(string $name, string $source = 'wikidata', string $state = 'unverified', array $attrs = [], string $letter = 'P'): int
    {
        return (int) $this->db->fetchOne(
            "INSERT INTO item (letter, name, geom, country_code, state, source, source_ref, attributes, created_at, updated_at)
             VALUES (:letter, :name, ST_SetSRID(ST_MakePoint(7.8, 46.0), 4326), 'CH', :state, :source, :ref, :attrs, NOW(), NOW())
             RETURNING id",
            ['letter' => $letter, 'name' => $name, 'state' => $state, 'source' => $source,
                'ref' => $source.':'.bin2hex(random_bytes(4)), 'attrs' => json_encode($attrs, \JSON_THROW_ON_ERROR)],
        );
    }

    /** @param array<int, array{covered: bool, nearest_bikeway_m: ?int}> $rows */
    private function review(array $rows, int $withinM = 250): string
    {
        $items = [];
        foreach ($rows as $id => $row) {
            $items[] = ['id' => $id] + $row;
        }
        $path = $this->dir.'/review.json';
        file_put_contents($path, json_encode(['withinM' => $withinM, 'items' => $items], \JSON_THROW_ON_ERROR));

        return $path;
    }

    /** @param array<string, mixed> $args */
    private function run_(array $args): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:scenic:bikeway-review'));
        $tester->execute($args);

        return $tester;
    }

    private function state(int $id): string
    {
        return (string) $this->db->fetchOne('SELECT state FROM item WHERE id = :id', ['id' => $id]);
    }

    public function testExportListsScenicItemsThatAreNotRetired(): void
    {
        $keep = $this->item('Dufourspitze');
        $this->item('Old view', state: 'retired');
        $this->item('A water tap', letter: 'B');

        $path = $this->dir.'/items.json';
        $this->run_(['--export' => $path])->assertCommandIsSuccessful();

        $ids = array_column(json_decode((string) file_get_contents($path), true), 'id');
        self::assertContains($keep, $ids);
        foreach (json_decode((string) file_get_contents($path), true) as $row) {
            self::assertSame(['id', 'lat', 'lng'], array_keys($row));
        }
        self::assertCount(\count(array_unique($ids)), $ids);
    }

    public function testAnUntouchedHarvestedItemAwayFromABikeWayIsRetired(): void
    {
        $summit = $this->item('Dufourspitze');
        $roadside = $this->item('Puy de Dome', source: 'osm');

        $tester = $this->run_(['--apply' => $this->review([
            $summit => ['covered' => true, 'nearest_bikeway_m' => 2400],
            $roadside => ['covered' => true, 'nearest_bikeway_m' => 40],
        ])]);

        $tester->assertCommandIsSuccessful();
        self::assertSame('retired', $this->state($summit));
        self::assertSame('unverified', $this->state($roadside));
        self::assertStringContainsString('Dufourspitze', $tester->getDisplay());
    }

    public function testNoBikeWayInTheSearchBoxIsAFail(): void
    {
        $summit = $this->item('Eiger');

        $this->run_(['--apply' => $this->review([$summit => ['covered' => true, 'nearest_bikeway_m' => null]])]);

        self::assertSame('retired', $this->state($summit));
    }

    public function testAnItemNoExtractCoversIsLeftAlone(): void
    {
        $canyon = $this->item('Grand Canyon National Park');

        $tester = $this->run_(['--apply' => $this->review([$canyon => ['covered' => false, 'nearest_bikeway_m' => null]])]);

        self::assertSame('unverified', $this->state($canyon), 'missing data is not a verdict');
        self::assertStringContainsString('Grand Canyon National Park', $tester->getDisplay());
    }

    public function testAnItemAPersonTouchedIsListedNotRetired(): void
    {
        $verified = $this->item('Verified summit', state: 'verified');
        $rider = $this->item('Rider view', source: 'user');
        $bestOf = $this->item('Best-of view', attrs: ['cur' => true]);
        $confirmed = $this->item('Confirmed view');
        $confirmer = (new User())->setEmail('scenic-confirm@cyclingcommons.org');
        $confirmer->setPassword('x');
        $confirmer->setDisplayName('Confirming Rider');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($confirmer);
        $em->flush();
        $this->db->executeStatement(
            "INSERT INTO item_confirmation (item_id, user_id, stance, source, by_curator, created_at, updated_at)
             VALUES (:id, :user, 'exists', 'drawer', false, NOW(), NOW())",
            ['id' => $confirmed, 'user' => $confirmer->getId()],
        );
        $far = ['covered' => true, 'nearest_bikeway_m' => 3000];

        $tester = $this->run_(['--apply' => $this->review([$verified => $far, $rider => $far, $bestOf => $far, $confirmed => $far])]);

        $tester->assertCommandIsSuccessful();
        foreach ([$verified, $rider, $bestOf, $confirmed] as $id) {
            self::assertNotSame('retired', $this->state($id));
        }
        self::assertStringContainsString('need a person', $tester->getDisplay());
        self::assertStringContainsString('Confirmed view', $tester->getDisplay());
    }

    public function testADryRunRetiresNothing(): void
    {
        $summit = $this->item('Jungfrau');

        $tester = $this->run_(['--apply' => $this->review([$summit => ['covered' => true, 'nearest_bikeway_m' => 5000]]), '--dry-run' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertSame('unverified', $this->state($summit));
        self::assertStringContainsString('Would retire', $tester->getDisplay());
    }
}
