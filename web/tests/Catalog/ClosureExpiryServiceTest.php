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
use App\Entity\User;
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
        $this->db->executeStatement("DELETE FROM submission WHERE item_id IN (SELECT id FROM item WHERE source_ref LIKE 'test:closure%')");
        $this->db->executeStatement("DELETE FROM item WHERE source_ref LIKE 'test:closure%'");
        $this->db->executeStatement("DELETE FROM users WHERE email LIKE 'closure-%@example.com'");
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

    /**
     * Scout tags a ride days before it is uploaded; the tap is the observation.
     * The row is created today, the rider tapped it in March: the clock starts
     * at the tap, kept as `observedAt` on the submission that created the row.
     */
    public function testTheScoutTapDateStartsTheClockNotTheUpload(): void
    {
        $item = $this->hazard('scout', ['hazardType' => ClosureLifetime::CLOSED_TYPE, 'closedFor' => 'Days'], '2026-05-31 08:00:00');
        $this->creatingSubmission($item, '2026-03-01');

        $due = $this->service()->due();

        self::assertCount(1, $due, 'closed for days, tapped in March: not news in June');
        self::assertSame('2026-03-01', $due[0]['observedAt']);
    }

    public function testAConfirmationAfterTheTapStillRestartsTheWindow(): void
    {
        $item = $this->hazard('scout-confirmed', ['hazardType' => ClosureLifetime::CLOSED_TYPE, 'closedFor' => 'Days'], '2026-05-31 08:00:00');
        $this->creatingSubmission($item, '2026-03-01');
        $this->confirm($item, '2026-05-30 08:00:00');

        self::assertSame([], $this->service()->due());
    }

    public function testAnImpossibleStoredDateFallsBackToTheUploadAndBreaksNothing(): void
    {
        // A hand-made payload can hold any string: one bad row must not stop every sweep.
        $bad = $this->hazard('scout-nodate', ['hazardType' => ClosureLifetime::CLOSED_TYPE, 'closedFor' => 'Days'], '2026-05-30 08:00:00');
        $this->creatingSubmission($bad, '2026-02-30');
        $stale = $this->hazard('scout-stale', ['hazardType' => ClosureLifetime::CLOSED_TYPE, 'closedFor' => 'Days'], '2026-03-01 08:00:00');

        $due = $this->service()->due();

        self::assertSame([(int) $stale->getId()], array_column($due, 'id'), 'the bad date reads as the upload, two days ago');
    }

    public function testADeviceClockFarBehindIsIgnored(): void
    {
        // The FIT epoch: a device with no clock fix. Taken at its word, a closure seen yesterday would retire at once.
        $item = $this->hazard('scout-epoch', ['hazardType' => ClosureLifetime::CLOSED_TYPE, 'closedFor' => 'Days'], '2026-05-31 08:00:00');
        $this->creatingSubmission($item, '1989-12-31');

        self::assertSame([], $this->service()->due());
    }

    public function testADeviceClockAheadIsClampedToTheUpload(): void
    {
        // Taken at its word, 2099 would keep the closure on the map for ever.
        $item = $this->hazard('scout-ahead', ['hazardType' => ClosureLifetime::CLOSED_TYPE, 'closedFor' => 'Days'], '2026-03-01 08:00:00');
        $this->creatingSubmission($item, '2099-01-01');

        $due = $this->service()->due();

        self::assertSame([(int) $item->getId()], array_column($due, 'id'));
        self::assertSame('2026-03-01', $due[0]['observedAt']);
    }

    public function testTheNewestObservationComesFirst(): void
    {
        $older = $this->hazard('order-old', ['hazardType' => ClosureLifetime::CLOSED_TYPE, 'closedFor' => 'Today'], '2026-02-01 08:00:00');
        $newer = $this->hazard('order-new', ['hazardType' => ClosureLifetime::CLOSED_TYPE, 'closedFor' => 'Today'], '2026-05-31 08:00:00');
        $this->creatingSubmission($newer, '2026-04-01');

        self::assertSame([(int) $newer->getId(), (int) $older->getId()], array_column($this->service()->due(), 'id'));
    }

    private function creatingSubmission(Item $item, string $observedAt): void
    {
        $user = new User();
        $user->setEmail('closure-'.$item->getId().'@example.com');
        $user->setDisplayName('Closure rider');
        $user->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();
        $userId = (int) $user->getId();
        $this->db->executeStatement(
            "INSERT INTO submission (type, letter, item_id, user_id, status, title, geom, country_code, changes, payload, created_at)
             VALUES ('new', 'E', :item, :user, 'approved', 'Test', ST_SetSRID(ST_MakePoint(4.87, 50.47), 4326), 'BE', '{}', :payload, NOW())",
            ['item' => $item->getId(), 'user' => $userId, 'payload' => json_encode(['via' => 'scout', 'observedAt' => $observedAt], \JSON_THROW_ON_ERROR)],
        );
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
