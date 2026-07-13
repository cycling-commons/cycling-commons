<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\ChangeHistory;
use App\Catalog\Entity\Item;
use App\Catalog\Entity\Submission;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use App\Entity\User;
use App\Moderation\AlreadyDecidedException;
use App\Moderation\ModerationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ModerationServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ModerationService $service;
    private User $curator;
    private int $submitterId;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->service = static::getContainer()->get(ModerationService::class);
        $this->curator = (new User())->setEmail('curator@decide.test');
        $this->curator->setPassword('x');
        $this->em->persist($this->curator);

        // decide() now also writes the submitter a message (M2), and
        // user_message.user_id carries a real DB FK to users(id) — every
        // seeded submission below needs a genuinely persisted submitter.
        $submitter = (new User())->setEmail('submitter@decide.test');
        $submitter->setPassword('x');
        $this->em->persist($submitter);

        $this->em->flush();
        $this->submitterId = (int) $submitter->getId();
    }

    /** @return array{Item, Submission} */
    private function seedNew(): array
    {
        $item = (new Item())->setLetter('B')->setName('Côte du Test')
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setState(ItemState::Submitted)->setSource(ItemSource::User)->setSourceRef('sub:pending')
            ->setAttributes(['len' => 3.1]);
        $this->em->persist($item);
        $this->em->flush();
        $sub = (new Submission())->setType(SubmissionType::NewItem)->setLetter('B')->setUserId($this->submitterId)
            ->setItemId($item->getId())->setTitle('Côte du Test')
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setChanges(['len' => ['was' => null, 'now' => 3.1]])->setPayload([]);
        $this->em->persist($sub);
        $this->em->flush();

        return [$item, $sub];
    }

    /** @return array{Item, Submission} */
    private function seedEdit(array $itemAttrs, array $changes): array
    {
        $item = (new Item())->setLetter('D')->setName('Repair station')
            ->setGeom('{"type":"Point","coordinates":[6.0,50.4]}')->setCountryCode('BE')
            ->setState(ItemState::Unverified)->setSource(ItemSource::Osm)->setSourceRef('node/7')
            ->setAttributes($itemAttrs);
        $this->em->persist($item);
        $this->em->flush();
        $sub = (new Submission())->setType(SubmissionType::Edit)->setLetter('D')->setUserId($this->submitterId)
            ->setItemId($item->getId())->setTitle('Repair station')
            ->setGeom('{"type":"Point","coordinates":[6.0,50.4]}')->setCountryCode('BE')
            ->setChanges($changes)->setPayload([]);
        $this->em->persist($sub);
        $this->em->flush();

        return [$item, $sub];
    }

    public function testApproveNewFlipsItemToUnverifiedWithHistory(): void
    {
        [$item, $sub] = $this->seedNew();
        $decided = $this->service->decide($sub->getId(), 'approve', $this->curator, null);

        self::assertSame(SubmissionStatus::Approved, $decided->getStatus());
        self::assertNotNull($decided->getDecidedAt());
        $this->em->refresh($item);
        self::assertSame(ItemState::Unverified, $item->getState());

        $history = $this->em->getRepository(ChangeHistory::class)->findBy(['itemId' => $item->getId()]);
        self::assertCount(1, $history);
        self::assertSame('state', $history[0]->getField());
        self::assertSame('submitted', $history[0]->getOldValue());
        self::assertSame('unverified', $history[0]->getNewValue());
    }

    public function testApproveWithMissingItemThrowsAndLeavesSubmissionPending(): void
    {
        [$item, $sub] = $this->seedEdit(['t' => 'Repair station'], ['pump' => ['was' => null, 'now' => 'yes']]);
        $subId = $sub->getId();

        // The target item vanishes before the decision is applied.
        $this->em->remove($item);
        $this->em->flush();
        $this->em->clear();

        try {
            $this->service->decide($subId, 'approve', $this->curator, null);
            self::fail('approving a submission whose item is gone must throw, not silently succeed');
        } catch (\InvalidArgumentException) {
            // expected
        }

        $reloaded = $this->em->find(Submission::class, $subId);
        self::assertNotNull($reloaded);
        self::assertSame(SubmissionStatus::Pending, $reloaded->getStatus(), 'rolled back — not marked approved');
    }

    public function testApproveEditAppliesChangesWithPerFieldHistory(): void
    {
        [$item, $sub] = $this->seedEdit(
            ['t' => 'Repair station', 'hours' => '24/7'],
            ['hours' => ['was' => '24/7', 'now' => 'closed Sundays'], 'pump' => ['was' => null, 'now' => 'yes']],
        );
        $this->service->decide($sub->getId(), 'approve', $this->curator, 'checked on site');

        $this->em->refresh($item);
        self::assertSame('closed Sundays', $item->getAttributes()['hours']);
        self::assertSame('yes', $item->getAttributes()['pump']);
        self::assertSame('Repair station', $item->getAttributes()['t'], 'untouched fields survive');

        $history = $this->em->getRepository(ChangeHistory::class)->findBy(['itemId' => $item->getId()], ['field' => 'ASC']);
        self::assertCount(2, $history);
        self::assertSame(['hours', 'pump'], [$history[0]->getField(), $history[1]->getField()]);
    }

    /**
     * C2-T6 (spec §W2): the climb form↔drawer reconciliation round-trips —
     * an approved 'effort' edit updates the item's attribute and leaves a
     * change_history row (shows up in the C1-T3 drawer history view).
     */
    public function testApproveEditUpdatesClimbEffortWithHistory(): void
    {
        $item = (new Item())->setLetter('B')->setName('Côte du Test Effort')
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setState(ItemState::Unverified)->setSource(ItemSource::User)->setSourceRef('sub:effort')
            ->setAttributes(['effort' => 'Steady', 'famousFor' => 'Nothing yet']);
        $this->em->persist($item);
        $this->em->flush();
        $sub = (new Submission())->setType(SubmissionType::Edit)->setLetter('B')->setUserId($this->submitterId)
            ->setItemId($item->getId())->setTitle('Côte du Test Effort')
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setChanges(['effort' => ['was' => 'Steady', 'now' => 'Very steep']])->setPayload([]);
        $this->em->persist($sub);
        $this->em->flush();

        $this->service->decide($sub->getId(), 'approve', $this->curator, null);

        $this->em->refresh($item);
        self::assertSame('Very steep', $item->getAttributes()['effort']);

        $history = $this->em->getRepository(ChangeHistory::class)->findOneBy(['itemId' => $item->getId(), 'field' => 'effort']);
        self::assertNotNull($history);
        self::assertSame('Steady', $history->getOldValue());
        self::assertSame('Very steep', $history->getNewValue());
    }

    public function testStaleWasIsCorrectedFromLiveItem(): void
    {
        // Item moved on since the snapshot: current hours is 'weekends only'.
        [$item, $sub] = $this->seedEdit(
            ['hours' => 'weekends only'],
            ['hours' => ['was' => '24/7', 'now' => 'closed Sundays']],
        );
        $this->service->decide($sub->getId(), 'approve', $this->curator, null);

        $history = $this->em->getRepository(ChangeHistory::class)->findOneBy(['itemId' => $item->getId()]);
        self::assertSame('weekends only', $history->getOldValue(), 'history records the ACTUAL old value');
    }

    public function testRejectNewMarksItemRejectedNoAttributeHistory(): void
    {
        [$item, $sub] = $this->seedNew();
        $this->service->decide($sub->getId(), 'reject', $this->curator, 'duplicate');

        $this->em->refresh($item);
        self::assertSame(ItemState::Rejected, $item->getState());
        self::assertSame('duplicate', $this->em->find(Submission::class, $sub->getId())->getDecisionNote());
    }

    public function testNeedsInfoParksWithoutMutation(): void
    {
        [$item, $sub] = $this->seedEdit(['hours' => '24/7'], ['hours' => ['was' => '24/7', 'now' => 'x']]);
        $this->service->decide($sub->getId(), 'needs_info', $this->curator, 'photo please');

        $this->em->refresh($item);
        self::assertSame('24/7', $item->getAttributes()['hours']);
        self::assertSame(SubmissionStatus::NeedsInfo, $this->em->find(Submission::class, $sub->getId())->getStatus());
        self::assertCount(0, $this->em->getRepository(ChangeHistory::class)->findAll());
    }

    public function testDecidingTwiceThrows(): void
    {
        [, $sub] = $this->seedNew();
        $this->service->decide($sub->getId(), 'approve', $this->curator, null);
        $this->expectException(AlreadyDecidedException::class);
        $this->service->decide($sub->getId(), 'reject', $this->curator, null);
    }

    public function testHistoryIsAppendOnlyAcrossDecisions(): void
    {
        // Spec §8 invariant: successive approvals APPEND rows; earlier rows
        // are never rewritten.
        [$item, $sub1] = $this->seedEdit(['hours' => '24/7'], ['hours' => ['was' => '24/7', 'now' => 'closed Sundays']]);
        $this->service->decide($sub1->getId(), 'approve', $this->curator, null);

        $sub2 = (new Submission())->setType(SubmissionType::Edit)->setLetter('D')->setUserId($this->submitterId)
            ->setItemId($item->getId())->setTitle('Repair station')
            ->setGeom('{"type":"Point","coordinates":[6.0,50.4]}')->setCountryCode('BE')
            ->setChanges(['hours' => ['was' => 'closed Sundays', 'now' => 'weekdays only']])->setPayload([]);
        $this->em->persist($sub2);
        $this->em->flush();
        $this->service->decide($sub2->getId(), 'approve', $this->curator, null);

        $history = $this->em->getRepository(ChangeHistory::class)->findBy(['itemId' => $item->getId()], ['id' => 'ASC']);
        self::assertCount(2, $history);
        self::assertSame('24/7', $history[0]->getOldValue(), 'first row untouched by second decision');
        self::assertSame('closed Sundays', $history[1]->getOldValue());
        self::assertSame('weekdays only', $history[1]->getNewValue());
    }
}
