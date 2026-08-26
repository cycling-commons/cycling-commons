<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Command;

use App\Catalog\ClosureLifetime;
use App\Catalog\Entity\Item;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:catalog:expire-closures` (test-suite review 2026-08-24).
 *
 * ClosureExpiryService is well covered by ClosureExpiryServiceTest. The
 * COMMAND was not, and the command owns the one decision the service does not:
 * which of `due()` and `sweep()` to call. Getting that backwards is a
 * scheduled job that silently retires rows when an operator asked to look, or
 * one that reports for months and never writes. Neither shows up in a service
 * test.
 *
 * @see docs/specs/edit-items/E-hazards.md
 */
final class ExpireClosuresCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $db;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->db = $this->em->getConnection();
        $this->db->executeStatement("DELETE FROM item WHERE source_ref LIKE 'test:expire-cmd%'");
    }

    public function testDryRunIsTheDefaultAndChangesNothing(): void
    {
        $item = $this->staleClosure('dry');

        $tester = $this->run_([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('would be retired', $tester->getDisplay());
        self::assertStringContainsString('--write', $tester->getDisplay());
        // The row is untouched: a bare run is a report, and an operator who
        // reads one must be able to trust that nothing moved.
        self::assertSame(ItemState::Verified->value, $this->stateOf($item));
    }

    public function testWriteRetiresTheClosure(): void
    {
        $item = $this->staleClosure('write');

        $tester = $this->run_(['--write' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('retired', $tester->getDisplay());
        self::assertStringNotContainsString('would be retired', $tester->getDisplay());
        self::assertNotSame(ItemState::Verified->value, $this->stateOf($item));
    }

    public function testTheReportNamesTheClosureAndItsWindow(): void
    {
        $this->staleClosure('named');

        $display = $this->run_([])->getDisplay();

        // The table is the whole product of a dry run. An operator decides
        // whether to pass --write by reading it.
        self::assertStringContainsString('Test expire-cmd:named', $display);
        self::assertStringContainsString('Days', $display);
    }

    public function testAFreshClosureIsLeftAlone(): void
    {
        $item = $this->closure('fresh', 'Months', '-3 days');

        $tester = $this->run_(['--write' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('No closures are past their window.', $tester->getDisplay());
        self::assertSame(ItemState::Verified->value, $this->stateOf($item));
    }

    public function testANonClosureHazardIsNeverTouched(): void
    {
        // Only `hazardType: Road closed` expires itself. A pothole reported two
        // years ago is still a pothole.
        $item = $this->hazard('pothole', ['hazardType' => 'Pothole'], '-2 years');

        $this->run_(['--write' => true]);

        self::assertSame(ItemState::Verified->value, $this->stateOf($item));
    }

    /** @param array<string, mixed> $args */
    private function run_(array $args): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:catalog:expire-closures'));
        $tester->execute($args);

        return $tester;
    }

    private function staleClosure(string $ref): Item
    {
        // "Closed for days" observed a year ago: past any window the spec allows.
        return $this->closure($ref, 'Days', '-1 year');
    }

    private function closure(string $ref, string $closedFor, string $observedAgo): Item
    {
        return $this->hazard($ref, [
            'hazardType' => ClosureLifetime::CLOSED_TYPE,
            ClosureLifetime::FIELD => $closedFor,
        ], $observedAgo);
    }

    /** @param array<string, mixed> $attributes */
    private function hazard(string $ref, array $attributes, string $observedAgo): Item
    {
        $item = (new Item())
            ->setLetter('E')
            ->setName('Test expire-cmd:'.$ref)
            ->setGeom('{"type":"Point","coordinates":[4.87,50.47]}')
            ->setCountryCode('BE')
            ->setSource(ItemSource::Manual)
            ->setSourceRef('test:expire-cmd:'.$ref)
            ->setState(ItemState::Verified)
            ->setAttributes($attributes);
        $this->em->persist($item);
        $this->em->flush();

        // created_at is stamped in the constructor; backdate it so the row can
        // be "observed" a year ago without waiting a year.
        $at = (new \DateTimeImmutable())->modify($observedAgo)->format('Y-m-d H:i:s');
        $this->db->executeStatement(
            'UPDATE item SET created_at = :t, updated_at = :t WHERE id = :id',
            ['t' => $at, 'id' => $item->getId()],
        );
        $this->em->refresh($item);

        return $item;
    }

    private function stateOf(Item $item): string
    {
        return (string) $this->db->fetchOne('SELECT state FROM item WHERE id = :id', ['id' => $item->getId()]);
    }
}
