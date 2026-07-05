<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\Submission;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use App\Moderation\RelativeTime;
use App\Moderation\SubmissionQueue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SubmissionQueueTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private SubmissionQueue $queue;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->queue = static::getContainer()->get(SubmissionQueue::class);
    }

    private function seed(string $type, string $country, SubmissionStatus $status = SubmissionStatus::Pending, string $title = 'Seeded'): Submission
    {
        $sub = (new Submission())
            ->setType(SubmissionType::from($type))->setLetter('D')->setUserId(7)
            ->setStatus($status)->setTitle($title)
            ->setGeom('{"type":"Point","coordinates":[5.5,50.5]}')
            ->setCountryCode($country)
            ->setChanges(['hours' => ['was' => '24/7', 'now' => 'closed Sundays']])
            ->setPayload([]);
        $this->em->persist($sub);
        $this->em->flush();

        return $sub;
    }

    public function testEmptyFiltersReturnPendingAndNeedsInfo(): void
    {
        $this->seed('new', 'BE');
        $this->seed('edit', 'NL', SubmissionStatus::NeedsInfo);
        $this->seed('edit', 'FR', SubmissionStatus::Approved); // decided — never queued

        $items = $this->queue->filtered(null, null, null);
        self::assertCount(2, $items);
    }

    public function testEachFilterNarrows(): void
    {
        $this->seed('new', 'BE');
        $this->seed('edit', 'NL');

        self::assertCount(1, $this->queue->filtered('BE', null, null));
        self::assertCount(1, $this->queue->filtered(null, null, 'edit'));
        self::assertCount(0, $this->queue->filtered('BE', null, 'edit'));
    }

    public function testShapeMatchesTheMapContract(): void
    {
        $this->seed('edit', 'BE');
        $item = $this->queue->filtered(null, null, null)[0];

        foreach (['id', 'type', 'letter', 'country', 'region', 'title', 'lat', 'lng', 'who', 'when', 'body', 'was', 'now'] as $key) {
            self::assertArrayHasKey($key, $item);
        }
        self::assertSame('hours: 24/7', $item['was']);
        self::assertSame('hours: closed Sundays', $item['now']);
        self::assertMatchesRegularExpression('/^rider#[0-9a-f]{4}$/', $item['who']);
        self::assertIsFloat($item['lat']);
    }

    public function testCountriesAreSortedDistinct(): void
    {
        $this->seed('new', 'NL');
        $this->seed('new', 'BE');
        $this->seed('new', 'BE');
        self::assertSame(['BE', 'NL'], $this->queue->countries());
    }

    public function testPendingForMapExcludesNeedsInfo(): void
    {
        $this->seed('new', 'BE');
        $this->seed('new', 'BE', SubmissionStatus::NeedsInfo);
        self::assertCount(1, $this->queue->pendingForMap());
    }

    public function testRelativeTime(): void
    {
        $now = new \DateTimeImmutable('2026-07-04 12:00');
        self::assertSame('2h ago', RelativeTime::ago(new \DateTimeImmutable('2026-07-04 10:00'), $now));
        self::assertSame('3d ago', RelativeTime::ago(new \DateTimeImmutable('2026-07-01 11:00'), $now));
        self::assertSame('just now', RelativeTime::ago(new \DateTimeImmutable('2026-07-04 11:59:40'), $now));
    }

    // ── Ported filter-semantics coverage (now exercised on real DB rows) ───────

    public function testItemsCarryCountryAndRegion(): void
    {
        $this->seed('new', 'BE');
        foreach ($this->queue->filtered(null, null, null) as $item) {
            self::assertArrayHasKey('country', $item);
            self::assertArrayHasKey('region', $item);
            self::assertNotSame('', $item['country']);
        }
    }

    public function testFilterByCountryReturnsOnlyThatCountry(): void
    {
        $this->seed('new', 'NL');
        $this->seed('new', 'BE');

        $nl = $this->queue->filtered('NL', null, null);
        self::assertNotEmpty($nl);
        foreach ($nl as $item) {
            self::assertSame('NL', $item['country']);
        }
        self::assertLessThan(\count($this->queue->filtered(null, null, null)), \count($nl));
    }

    public function testFilterByTypeReturnsOnlyThatType(): void
    {
        $this->seed('hazard', 'BE');
        $this->seed('new', 'BE');

        $hazards = $this->queue->filtered(null, null, 'hazard');
        self::assertNotEmpty($hazards);
        foreach ($hazards as $item) {
            self::assertSame('hazard', $item['type']);
        }
    }

    public function testCountriesAndRegionsAreSortedDistinct(): void
    {
        $this->seed('new', 'NL');
        $this->seed('new', 'BE');
        $this->seed('new', 'BE');

        $countries = $this->queue->countries();
        self::assertContains('BE', $countries);
        self::assertContains('NL', $countries);
        self::assertSame(array_values(array_unique($countries)), $countries);
        $sorted = $countries;
        sort($sorted);
        self::assertSame($sorted, $countries);
    }

    public function testTotalCountsPendingAndNeedsInfoOnly(): void
    {
        $this->seed('new', 'BE');
        $this->seed('edit', 'NL', SubmissionStatus::NeedsInfo);
        $this->seed('edit', 'FR', SubmissionStatus::Approved);

        self::assertSame(2, $this->queue->total());
    }
}
