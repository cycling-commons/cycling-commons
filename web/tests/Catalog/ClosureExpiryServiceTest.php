<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\ClosureExpiryService;
use App\Catalog\ClosureLifetime;
use App\Catalog\ConfirmationSource;
use App\Catalog\ConfirmationStance;
use App\Catalog\Entity\Item;
use App\Catalog\Entity\ItemConfirmation;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class ClosureExpiryServiceTest extends KernelTestCase
{
    private const string NOW = '2026-06-01 09:00:00';

    private EntityManagerInterface $em;
    private Connection $db;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->db = $this->em->getConnection();
        $this->db->executeStatement("DELETE FROM item_confirmation WHERE item_id IN (SELECT id FROM item WHERE source_ref LIKE 'test:closure%')");
        $this->db->executeStatement("DELETE FROM change_history WHERE item_id IN (SELECT id FROM item WHERE source_ref LIKE 'test:closure%')");
        $this->db->executeStatement("DELETE FROM item WHERE source_ref LIKE 'test:closure%'");
    }

    private function service(string $now = self::NOW): ClosureExpiryService
    {
        return new ClosureExpiryService($this->db, $this->em, new MockClock(new \DateTimeImmutable($now)), new ArrayAdapter());
    }

    /** @param array<string, mixed> $attributes */
    private function hazard(string $ref, array $attributes, string $createdAt): Item
    {
        $item = (new Item())
            ->setLetter('E')
            ->setName('Test '.$ref)
            ->setGeom('{"type":"Point","coordinates":[4.87,50.47]}')
            ->setCountryCode('BE')
            ->setSource(ItemSource::Manual)
            ->setSourceRef('test:closure:'.$ref)
            ->setState(ItemState::Verified)
            ->setAttributes($attributes);
        $this->em->persist($item);
        $this->em->flush();

        // created_at is set in the constructor; backdate it in SQL so the row
        // can be "observed" months ago without waiting.
        $this->db->executeStatement(
            'UPDATE item SET created_at = :t, updated_at = :t WHERE id = :id',
            ['t' => $createdAt, 'id' => $item->getId()],
        );
        $this->em->refresh($item);

        return $item;
    }

    private function confirm(Item $item, string $at, ConfirmationSource $source = ConfirmationSource::Drawer): void
    {
        $c = new ItemConfirmation((int) $item->getId(), 4242, ConfirmationStance::Exists, $source);
        $this->em->persist($c);
        $this->em->flush();
        $this->db->executeStatement(
            'UPDATE item_confirmation SET created_at = :t, updated_at = :t WHERE id = :id',
            ['t' => $at, 'id' => $c->getId()],
        );
    }

    private function stateOf(Item $item): string
    {
        return (string) $this->db->fetchOne('SELECT state FROM item WHERE id = :id', ['id' => $item->getId()]);
    }

    public function testAClosurePastItsStatedWindowIsRetired(): void
    {
        // "Closed for days" seen in March; ten days later it is not news.
        $item = $this->hazard('stale', ['hazardType' => ClosureLifetime::CLOSED_TYPE, 'closedFor' => 'Days'], '2026-03-01 08:00:00');

        $swept = $this->service()->sweep();

        self::assertCount(1, $swept);
        self::assertSame((int) $item->getId(), $swept[0]['id']);
        self::assertSame(ItemState::Retired->value, $this->stateOf($item));
    }

    public function testARecentClosureIsLeftAlone(): void
    {
        $item = $this->hazard('fresh', ['hazardType' => ClosureLifetime::CLOSED_TYPE, 'closedFor' => 'Months'], '2026-05-20 08:00:00');

        self::assertSame([], $this->service()->due());
        self::assertSame(ItemState::Verified->value, $this->stateOf($item));
    }

    /**
     * The "unless re-confirmed" half of the promise: a rider saying "still
     * closed" restarts the window, so a long roadworks closure that people keep
     * confirming never falls off the map.
     */
    public function testAConfirmationRestartsTheWindow(): void
    {
        $item = $this->hazard('reconfirmed', ['hazardType' => ClosureLifetime::CLOSED_TYPE, 'closedFor' => 'Days'], '2026-03-01 08:00:00');
        $this->confirm($item, '2026-05-28 08:00:00');

        self::assertSame([], $this->service()->due(), 'a confirmed-last-week closure is not stale');
        self::assertSame(ItemState::Verified->value, $this->stateOf($item));
    }

    /**
     * A `form` confirmation is the submitter answering their own contribution
     * on the improve form. Letting it restart the clock would mean one person
     * could keep their own closure alive for ever without anybody else ever
     * seeing the road — the same reason it is excluded from the verified tier.
     */
    public function testTheSubmittersOwnFormAnswerDoesNotRestartTheWindow(): void
    {
        $item = $this->hazard('selfconfirmed', ['hazardType' => ClosureLifetime::CLOSED_TYPE, 'closedFor' => 'Days'], '2026-03-01 08:00:00');
        $this->confirm($item, '2026-05-28 08:00:00', ConfirmationSource::Form);

        self::assertCount(1, $this->service()->due());
    }

    /** Scout tags a ride days before it is uploaded; the tap is the observation. */
    public function testAnExplicitObservationDateWinsOverTheRowsCreationDate(): void
    {
        // Row created today, but the rider tagged it three months ago.
        $item = $this->hazard(
            'scout',
            ['hazardType' => ClosureLifetime::CLOSED_TYPE, 'closedFor' => 'Days', 'observedOn' => '2026-03-01 08:00:00'],
            '2026-03-01 08:00:00',
        );

        self::assertCount(1, $this->service()->due());
        self::assertSame('2026-03-01', $this->service()->due()[0]['observedAt']);
        self::assertNotNull($item->getId());
    }

    public function testHazardsThatAreNotClosuresAreNeverTouched(): void
    {
        $ice = $this->hazard('ice', ['hazardType' => 'Ice / frost', 'closedFor' => 'Days'], '2020-01-01 08:00:00');

        self::assertSame([], $this->service()->due());
        self::assertSame(ItemState::Verified->value, $this->stateOf($ice));
    }

    /**
     * Quiet, not deleted. The closure was true when it was reported, and
     * keeping it is what makes a repeat closure legible next year.
     */
    public function testTheRowSurvivesAndTheExpiryIsOnTheRecord(): void
    {
        $item = $this->hazard('recorded', ['hazardType' => ClosureLifetime::CLOSED_TYPE, 'closedFor' => 'Today'], '2026-03-01 08:00:00');

        $this->service()->sweep();

        self::assertSame(
            1,
            (int) $this->db->fetchOne('SELECT count(*) FROM item WHERE id = :id', ['id' => $item->getId()]),
            'the row must not be deleted',
        );
        $history = $this->db->fetchAssociative(
            'SELECT field, old_value, new_value, changed_by FROM change_history WHERE item_id = :id',
            ['id' => $item->getId()],
        );
        self::assertIsArray($history);
        self::assertSame('state', $history['field']);
        self::assertSame('"verified"', $history['old_value']);
        self::assertSame('"retired"', $history['new_value']);
        self::assertSame(0, (int) $history['changed_by'], 'an automatic change has no author');
    }

    /** Re-running must not pile up history rows or fail on already-retired items. */
    public function testSweepingTwiceIsIdempotent(): void
    {
        $item = $this->hazard('twice', ['hazardType' => ClosureLifetime::CLOSED_TYPE, 'closedFor' => 'Today'], '2026-03-01 08:00:00');

        $this->service()->sweep();
        $this->service()->sweep();

        self::assertSame(
            1,
            (int) $this->db->fetchOne('SELECT count(*) FROM change_history WHERE item_id = :id', ['id' => $item->getId()]),
        );
    }
}
