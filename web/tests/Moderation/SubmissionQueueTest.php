<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\Item;
use App\Catalog\Entity\Region;
use App\Catalog\Entity\Submission;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
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

    private function item(): Item
    {
        $item = (new Item())
            ->setLetter('D')
            ->setName('Queue osm '.uniqid('', true))
            ->setGeom('{"type":"Point","coordinates":[5.5,50.5]}')
            ->setCountryCode('BE')
            ->setSource(ItemSource::User)
            ->setSourceRef('test:queue-osm:'.uniqid('', true))
            ->setState(ItemState::Submitted)
            ->setAttributes([]);
        $this->em->persist($item);
        $this->em->flush();

        return $item;
    }

    /**
     * The OSM question rides the row (catalog-data-model.md §5b): open with
     * its stored candidates, or answered either way. An edit has no question.
     */
    public function testRowsCarryTheOsmQuestionOfANewPlace(): void
    {
        $open = $this->item();
        $none = $this->item()->answerOsm(null);
        $linked = $this->item()->answerOsm('node/930340800');
        $this->em->flush();
        $this->seed('new', 'BE', SubmissionStatus::Pending, 'Open')->setItemId((int) $open->getId());
        $this->seed('new', 'BE', SubmissionStatus::Pending, 'None')->setItemId((int) $none->getId());
        $this->seed('new', 'BE', SubmissionStatus::Pending, 'Linked')->setItemId((int) $linked->getId());
        $this->seed('edit', 'BE', SubmissionStatus::Pending, 'Edit')->setItemId((int) $linked->getId());
        $this->em->flush();

        $rows = array_column($this->queue->pendingForMap(ModerationScope::global()), 'osm', 'title');
        self::assertSame(['state' => 'open', 'ref' => null, 'candidates' => []], $rows['Open']);
        self::assertSame(['state' => 'none', 'ref' => null, 'candidates' => []], $rows['None']);
        self::assertSame(['state' => 'linked', 'ref' => 'node/930340800', 'candidates' => []], $rows['Linked']);
        self::assertNull($rows['Edit']);
        // And the queue list reads the same rows.
        $listed = array_column($this->queue->filtered(ModerationScope::global(), null, null, null), 'osm', 'title');
        self::assertSame('open', $listed['Open']['state']);
    }

    /** The candidate list is a curator's (ContributeController::osmNearby): a rider's own pin carries none. */
    public function testARidersOwnPinsCarryNoOsmQuestion(): void
    {
        $this->seed('new', 'BE', SubmissionStatus::Pending, 'Mine')->setItemId((int) $this->item()->getId());
        $this->em->flush();

        $rows = array_column($this->queue->ownPendingForMap(7), 'osm', 'title');
        self::assertArrayHasKey('Mine', $rows);
        self::assertNull($rows['Mine']);
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

    /**
     * A suggested pin move on a scenic view tells the curator how many rider
     * photos it hides until a curator confirms them (scenic-views.md §8).
     */
    public function testAPinMoveOnAScenicViewSaysHowManyRiderPhotosItHides(): void
    {
        $here = [50.5, 5.5];
        $item = (new Item())->setLetter('P')->setName('Queue view')
            ->setGeom('{"type":"Point","coordinates":[5.5,50.5]}')->setCountryCode('BE')
            ->setSourceRef('queue-pin-move-'.bin2hex(random_bytes(3)))
            ->setSource(ItemSource::User)->setState(ItemState::Verified)
            ->setAttributes(['photos' => [
                ['id' => 'near', 'sm' => 'x', 'license' => 'CC BY-SA 4.0', 'credit' => '', 'distanceM' => 40, 'distancePin' => $here],
                ['id' => 'edge', 'sm' => 'x', 'license' => 'CC BY-SA 4.0', 'credit' => '', 'distanceM' => 240, 'distancePin' => $here],
            ]]);
        $this->em->persist($item);
        $this->em->flush();

        $edit = function (string $title, array $changes, string $letter = 'P') use ($item): void {
            $this->em->persist((new Submission())
                ->setType(SubmissionType::Edit)->setLetter($letter)->setUserId(7)
                ->setStatus(SubmissionStatus::Pending)->setTitle($title)
                ->setGeom('{"type":"Point","coordinates":[5.5,50.5]}')
                ->setCountryCode('BE')->setItemId($item->getId())
                ->setChanges($changes)->setPayload([]));
            $this->em->flush();
        };
        // About 20 m north: only the photo 240 m away goes. About 400 m: both.
        $edit('Small move', ['location' => ['was' => '50.500000, 5.500000', 'now' => '50.500180, 5.500000']]);
        $edit('Big move', ['location' => ['was' => '50.500000, 5.500000', 'now' => '50.503600, 5.500000']]);
        $edit('No move', ['name' => ['was' => 'Queue view', 'now' => 'Queue viewpoint']]);

        $rows = array_column($this->queue->pendingForMap(ModerationScope::global()), null, 'title');
        self::assertSame(1, $rows['Small move']['photosHiddenByMove']);
        self::assertSame(2, $rows['Big move']['photosHiddenByMove']);
        self::assertSame(0, $rows['No move']['photosHiddenByMove']);
        self::assertSame(0, $this->queue->filtered(ModerationScope::global(), null, null, null)[0]['photosHiddenByMove'] ?? null, 'the desk rows carry it too');
    }
}
