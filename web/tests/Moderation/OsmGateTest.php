<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\Item;
use App\Catalog\Entity\Submission;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\SubmissionType;
use App\Entity\User;
use App\Moderation\ModerationService;
use App\Moderation\OsmUnansweredException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The gate exists because every other path to this question is a command
 * somebody has to remember: the linker and the desk both work, and 0 of 1,525
 * rows were linked (catalog-data-model.md §5b, owner decision 2026-08-25).
 */
final class OsmGateTest extends WebTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function service(): ModerationService
    {
        return static::getContainer()->get(ModerationService::class);
    }

    private function curator(): User
    {
        $u = (new User())->setEmail('curator-'.uniqid('', true).'@osm-gate.test');
        $u->setPassword('x');
        $this->em()->persist($u);
        $this->em()->flush();

        return $u;
    }

    /** A rider-added place nobody has linked: the row every test here starts from. */
    private function unansweredItem(): Item
    {
        $item = (new Item())
            ->setLetter('P')
            ->setName('Uitkijkpunt Gate '.uniqid('', true))
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')
            ->setCountryCode('BE')
            ->setSource(ItemSource::User)
            ->setSourceRef('test:osm-gate:'.uniqid('', true))
            ->setState(ItemState::Submitted)
            ->setAttributes([]);
        $this->em()->persist($item);
        $this->em()->flush();

        return $item;
    }

    private function pending(SubmissionType $type, Item $item): Submission
    {
        $sub = (new Submission())->setType($type)->setLetter('P')->setUserId(1)
            ->setTitle('Osm gate '.$type->value)
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setChanges([])->setPayload([])
            ->setItemId((int) $item->getId());
        $this->em()->persist($sub);
        $this->em()->flush();

        return $sub;
    }

    public function testApprovingANewPlaceWithAnUnansweredQuestionIsRefused(): void
    {
        $sub = $this->pending(SubmissionType::NewItem, $this->unansweredItem());

        $this->expectException(OsmUnansweredException::class);
        $this->service()->decide($sub->getId(), 'approve', $this->curator(), null);
    }

    public function testLinkingFirstLetsTheApprovalThrough(): void
    {
        $item = $this->unansweredItem();
        $sub = $this->pending(SubmissionType::NewItem, $item);
        $item->answerOsm('node/930340800');
        $this->em()->flush();

        $decided = $this->service()->decide($sub->getId(), 'approve', $this->curator(), null);
        self::assertSame('approved', $decided->getStatus()->value);
    }

    public function testAnsweringNoCounterpartAlsoLetsItThrough(): void
    {
        $item = $this->unansweredItem();
        $sub = $this->pending(SubmissionType::NewItem, $item);
        $item->answerOsm(null);   // looked, and there is none
        $this->em()->flush();

        $decided = $this->service()->decide($sub->getId(), 'approve', $this->curator(), null);
        self::assertSame('approved', $decided->getStatus()->value);
    }

    public function testRejectingIsNeverGated(): void
    {
        $sub = $this->pending(SubmissionType::NewItem, $this->unansweredItem());

        $decided = $this->service()->decide($sub->getId(), 'reject', $this->curator(), 'spam');
        self::assertSame('rejected', $decided->getStatus()->value,
            'the gate is about admitting a place, not about turning one away');
    }

    public function testAnEditIsNeverGated(): void
    {
        $item = $this->unansweredItem();
        $item->setState(ItemState::Unverified);   // an edit targets a row that already lives
        $sub = $this->pending(SubmissionType::Edit, $item);
        $this->em()->flush();

        $decided = $this->service()->decide($sub->getId(), 'approve', $this->curator(), null);
        self::assertSame('approved', $decided->getStatus()->value);
    }

    public function testARefusedApprovalLeavesTheSubmissionUntouched(): void
    {
        $sub = $this->pending(SubmissionType::NewItem, $this->unansweredItem());

        try {
            $this->service()->decide($sub->getId(), 'approve', $this->curator(), null);
            self::fail('expected the gate to refuse');
        } catch (OsmUnansweredException) {
        }

        $this->em()->clear();
        $fresh = $this->em()->find(Submission::class, $sub->getId());
        self::assertNotNull($fresh);
        self::assertSame('pending', $fresh->getStatus()->value,
            'a refusal is a pause, not a decision: the row must still be decidable');
    }
}
