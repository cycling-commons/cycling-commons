<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\Region;
use App\Catalog\Entity\Submission;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use App\Moderation\ModerationScope;
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

    private function seed(string $type, string $country, SubmissionStatus $status = SubmissionStatus::Pending, string $title = 'Seeded', ?int $regionId = null): Submission
    {
        $sub = (new Submission())
            ->setType(SubmissionType::from($type))->setLetter('D')->setUserId(7)
            ->setStatus($status)->setTitle($title)
            ->setGeom('{"type":"Point","coordinates":[5.5,50.5]}')
            ->setCountryCode($country)->setRegionId($regionId)
            ->setChanges(['hours' => ['was' => '24/7', 'now' => 'closed Sundays']])
            ->setPayload([]);
        $this->em->persist($sub);
        $this->em->flush();

        return $sub;
    }

    private function seedRegion(string $slug, string $name, string $country): Region
    {
        $region = (new Region())->setSlug($slug)->setName($name)->setCountryCode($country)
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[4.0,49.5],[6.5,49.5],[6.5,51.0],[4.0,51.0],[4.0,49.5]]]]}');
        $this->em->persist($region);
        $this->em->flush();

        return $region;
    }

    /**
     * Region A (BE), region B (NL), region C (FR); one submission in each plus
     * one with region_id NULL — the fixture shared by the scoping tests.
     *
     * @return array{a: Region, b: Region, c: Region}
     */
    private function seedScopeFixture(): array
    {
        $a = $this->seedRegion('scope-a', 'Scope Region A', 'BE');
        $b = $this->seedRegion('scope-b', 'Scope Region B', 'NL');
        $c = $this->seedRegion('scope-c', 'Scope Region C', 'FR');

        $this->seed('new', 'BE', SubmissionStatus::Pending, 'In A', $a->getId());
        $this->seed('new', 'NL', SubmissionStatus::Pending, 'In B', $b->getId());
        $this->seed('new', 'FR', SubmissionStatus::Pending, 'In C', $c->getId());
        $this->seed('new', 'DE', SubmissionStatus::Pending, 'No region', null);

        return ['a' => $a, 'b' => $b, 'c' => $c];
    }

    public function testEmptyFiltersReturnPendingAndNeedsInfo(): void
    {
        $this->seed('new', 'BE');
        $this->seed('edit', 'NL', SubmissionStatus::NeedsInfo);
        $this->seed('edit', 'FR', SubmissionStatus::Approved); // decided — never queued

        $items = $this->queue->filtered(ModerationScope::global(), null, null, null);
        self::assertCount(2, $items);
    }

    public function testEachFilterNarrows(): void
    {
        $this->seed('new', 'BE');
        $this->seed('edit', 'NL');

        self::assertCount(1, $this->queue->filtered(ModerationScope::global(), 'BE', null, null));
        self::assertCount(1, $this->queue->filtered(ModerationScope::global(), null, null, 'edit'));
        self::assertCount(0, $this->queue->filtered(ModerationScope::global(), 'BE', null, 'edit'));
    }

    public function testShapeMatchesTheMapContract(): void
    {
        $this->seed('edit', 'BE');
        $item = $this->queue->filtered(ModerationScope::global(), null, null, null)[0];

        foreach (['id', 'itemId', 'type', 'letter', 'country', 'region', 'title', 'lat', 'lng', 'who', 'when', 'body', 'was', 'now'] as $key) {
            self::assertArrayHasKey($key, $item);
        }
        self::assertSame('hours: 24/7', $item['was']);
        self::assertSame('hours: closed Sundays', $item['now']);
        self::assertMatchesRegularExpression('/^rider#[0-9a-f]{4}$/', $item['who']);
        self::assertIsFloat($item['lat']);
    }

    /**
     * `body` comes from the improve form's nested `details.note` — the only
     * place any current form writes a rider note. A flat `payload.body` key is
     * written by no path (verified 2026-07-19: sole writer is
     * CatalogContributionService::submit()'s raw form payload) and is ignored.
     */
    public function testBodyReadsTheNestedDetailsNoteOnly(): void
    {
        $sub = $this->seed('edit', 'BE');
        $sub->setPayload(['details' => ['note' => 'low flow behind the church'], 'body' => 'stray flat key']);
        $this->em->flush();

        $item = $this->queue->filtered(ModerationScope::global(), null, null, null)[0];

        self::assertSame('low flow behind the church', $item['body']);
    }

    /**
     * The pending drawer needs the target item's id to fetch its applied
     * change history (moderation-UX: "always see the history of an item").
     * `seed()` never sets item_id, so it must come back null — not omitted.
     */
    public function testRowsCarryTargetItemId(): void
    {
        $this->seed('edit', 'BE');
        $item = $this->queue->filtered(ModerationScope::global(), null, null, null)[0];
        self::assertArrayHasKey('itemId', $item);
        self::assertNull($item['itemId']);

        $sub = (new Submission())
            ->setType(SubmissionType::from('edit'))->setLetter('D')->setUserId(7)
            ->setStatus(SubmissionStatus::Pending)->setTitle('Bound edit')
            ->setGeom('{"type":"Point","coordinates":[5.5,50.5]}')
            ->setCountryCode('BE')->setItemId(4242)
            ->setChanges(['hours' => ['was' => '24/7', 'now' => 'closed Sundays']])
            ->setPayload([]);
        $this->em->persist($sub);
        $this->em->flush();

        $bound = array_values(array_filter(
            $this->queue->filtered(ModerationScope::global(), null, null, null),
            static fn (array $row): bool => 'Bound edit' === $row['title'],
        ))[0];
        self::assertSame(4242, $bound['itemId']);
    }

    /**
     * A place a curator already turned down, proposed again. The rejected row
     * is REVIVED rather than twinned, so both reports hang off one item id —
     * and the queue row has to say so, or the curator overturns a decision
     * without knowing there was one (found 2026-08-12 while fixing the revive).
     */
    public function testPriorRejectionTravelsWithTheRow(): void
    {
        $old = $this->seed('new', 'BE', SubmissionStatus::Rejected, 'Turned down once');
        $old->setItemId(9001)
            ->setDecisionNote('the tap is a garden hose')
            ->setDecidedAt(new \DateTimeImmutable('2026-06-01 09:00'));
        $fresh = $this->seed('new', 'BE', SubmissionStatus::Pending, 'Proposed again');
        $fresh->setItemId(9001);
        // An unrelated pending row on another item must stay clean: the lookup
        // is keyed by item, not by "some rejection exists somewhere".
        $this->seed('new', 'BE', SubmissionStatus::Pending, 'Never rejected');
        $this->em->flush();

        $rows = [];
        foreach ($this->queue->filtered(ModerationScope::global(), null, null, null) as $row) {
            $rows[$row['title']] = $row;
        }

        self::assertArrayHasKey('priorRejection', $rows['Proposed again']);
        self::assertSame('the tap is a garden hose', $rows['Proposed again']['priorRejection']['note']);
        self::assertStringStartsWith('2026-06-01', $rows['Proposed again']['priorRejection']['when']);
        self::assertNull($rows['Never rejected']['priorRejection']);
        // And the decision surface reads the same rows the desk does.
        $onMap = array_column($this->queue->pendingForMap(ModerationScope::global()), 'priorRejection', 'title');
        self::assertNotNull($onMap['Proposed again']);
    }

    /** Only the newest rejection: an item turned down twice shows the last word. */
    public function testPriorRejectionTakesTheMostRecentDecision(): void
    {
        foreach ([['2026-03-01 09:00', 'first no'], ['2026-05-01 09:00', 'second no']] as [$when, $note]) {
            $this->seed('new', 'BE', SubmissionStatus::Rejected, 'Old '.$note)
                ->setItemId(9002)->setDecisionNote($note)->setDecidedAt(new \DateTimeImmutable($when));
        }
        $this->seed('new', 'BE', SubmissionStatus::Pending, 'Third try')->setItemId(9002);
        $this->em->flush();

        $row = array_values(array_filter(
            $this->queue->filtered(ModerationScope::global(), null, null, null),
            static fn (array $r): bool => 'Third try' === $r['title'],
        ))[0];

        self::assertSame('second no', $row['priorRejection']['note']);
    }

    public function testCountriesAreSortedDistinct(): void
    {
        $this->seed('new', 'NL');
        $this->seed('new', 'BE');
        $this->seed('new', 'BE');
        self::assertSame(['BE', 'NL'], $this->queue->countries(ModerationScope::global()));
    }

    public function testPendingForMapExcludesNeedsInfo(): void
    {
        $this->seed('new', 'BE');
        $this->seed('new', 'BE', SubmissionStatus::NeedsInfo);
        self::assertCount(1, $this->queue->pendingForMap(ModerationScope::global()));
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
        foreach ($this->queue->filtered(ModerationScope::global(), null, null, null) as $item) {
            self::assertArrayHasKey('country', $item);
            self::assertArrayHasKey('region', $item);
            self::assertNotSame('', $item['country']);
        }
    }

    public function testFilterByCountryReturnsOnlyThatCountry(): void
    {
        $this->seed('new', 'NL');
        $this->seed('new', 'BE');

        $nl = $this->queue->filtered(ModerationScope::global(), 'NL', null, null);
        self::assertNotEmpty($nl);
        foreach ($nl as $item) {
            self::assertSame('NL', $item['country']);
        }
        self::assertLessThan(\count($this->queue->filtered(ModerationScope::global(), null, null, null)), \count($nl));
    }

    public function testFilterByTypeReturnsOnlyThatType(): void
    {
        $this->seed('hazard', 'BE');
        $this->seed('new', 'BE');

        $hazards = $this->queue->filtered(ModerationScope::global(), null, null, 'hazard');
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

        $countries = $this->queue->countries(ModerationScope::global());
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

        self::assertSame(2, $this->queue->total(ModerationScope::global()));
    }

    // ── Moderator-areas scoping (task 2) ────────────────────────────────────

    public function testLimitedScopeSeesOwnRegionCountryAndNullRegionOnly(): void
    {
        $fixture = $this->seedScopeFixture();
        $scope = ModerationScope::limited([$fixture['a']->getId()], ['NL']);

        $titles = array_column($this->queue->filtered($scope, null, null, null), 'title');
        sort($titles);
        self::assertSame(['In A', 'In B', 'No region'], $titles);
        self::assertSame(3, $this->queue->total($scope));

        self::assertSame(['BE', 'DE', 'NL'], $this->queue->countries($scope));
        self::assertSame(['Scope Region A', 'Scope Region B'], $this->queue->regions($scope));
    }

    public function testGlobalScopeSeesEverything(): void
    {
        $this->seedScopeFixture();

        $items = $this->queue->filtered(ModerationScope::global(), null, null, null);
        self::assertCount(4, $items);
        self::assertSame(4, $this->queue->total(ModerationScope::global()));
    }

    public function testPendingForMapIsScoped(): void
    {
        $fixture = $this->seedScopeFixture();
        $scope = ModerationScope::limited([$fixture['a']->getId()], ['NL']);

        $titles = array_column($this->queue->pendingForMap($scope), 'title');
        self::assertNotContains('In C', $titles);
        sort($titles);
        self::assertSame(['In A', 'In B', 'No region'], $titles);
    }
}
