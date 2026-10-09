<?php

// SPDX-License-Identifier: AGPL-3.0-only

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
use App\Messaging\Entity\UserMessage;
use App\Messaging\UserMessageKind;
use App\Moderation\ModerationService;
use App\Moderation\ReplacedPlaces;
use App\Moderation\StatementDecision;
use App\Moderation\StatementGround;
use App\Moderation\StatementOfReasons;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * An approved place replaces the rows that are the same place.
 *
 * The case this was built for (production, 2026-09-27): a rider corrected an
 * OSM drinking-water point, which made it a submission holding that point,
 * while a provider's record of the same tap stood 7 m away. On approval the
 * rider's row must be the only one left.
 */
final class ReplacedPlacesTest extends KernelTestCase
{
    private const float LNG = 5.8601;
    private const float LAT = 50.4701;

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
        $this->curator = (new User())->setEmail('curator@replaces.test');
        $this->curator->setPassword('x');
        $this->em->persist($this->curator);
        $submitter = (new User())->setEmail('submitter@replaces.test');
        $submitter->setPassword('x');
        $this->em->persist($submitter);
        $this->em->flush();
        $this->submitterId = (int) $submitter->getId();
    }

    public function testTheProvidersRowHoldingTheSameOsmPointIsRetiredOnApproval(): void
    {
        $provider = $this->served('rivm-test:tap', 'node/88801', self::LAT + 0.00006, self::LNG);
        [$new, $sub] = $this->submitted('node/88801', ItemSource::Osm, 'node/88801', []);

        $this->service->decide((int) $sub->getId(), 'approve', $this->curator, null);

        $this->em->clear();
        self::assertSame(ItemState::Retired, $this->item($provider)->getState());
        self::assertSame(ItemState::Unverified, $this->item($new)->getState());
        self::assertSame('node/88801', $this->item($new)->getOsmRef());
        self::assertSame(
            (string) $new->getId(),
            (string) $this->history($provider, 'replaced_by')?->getNewValue(),
            'history says which place replaced it',
        );
    }

    public function testATickedPlaceIsRetiredAndItsOsmPointHandedOver(): void
    {
        // The provider row never got its OSM point; the rider ticked it.
        $provider = $this->served('rivm-test:tap2', 'node/88802', self::LAT + 0.00006, self::LNG);
        [$new, $sub] = $this->submitted(null, ItemSource::User, 'sub:replaces-1', ['item:'.$provider->getId()]);

        $this->service->decide((int) $sub->getId(), 'approve', $this->curator, null);

        $this->em->clear();
        self::assertSame(ItemState::Retired, $this->item($provider)->getState());
        self::assertSame('node/88802', $this->item($new)->getOsmRef(), 'the new place takes over the point');
    }

    public function testAnUntickedPlaceNearbyIsLeftAlone(): void
    {
        $neighbour = $this->served('rivm-test:other', null, self::LAT + 0.0009, self::LNG);
        [, $sub] = $this->submitted(null, ItemSource::User, 'sub:replaces-2', []);

        $this->service->decide((int) $sub->getId(), 'approve', $this->curator, null);

        $this->em->clear();
        self::assertSame(ItemState::Verified, $this->item($neighbour)->getState());
    }

    public function testATickFartherThanTheRadiusIsIgnored(): void
    {
        // About 330 m north: a hand-made tick, not one the wizard offered.
        $far = $this->served('rivm-test:far', null, self::LAT + 0.003, self::LNG);
        [, $sub] = $this->submitted(null, ItemSource::User, 'sub:replaces-3', ['item:'.$far->getId()]);

        $this->service->decide((int) $sub->getId(), 'approve', $this->curator, null);

        $this->em->clear();
        self::assertSame(ItemState::Verified, $this->item($far)->getState());
    }

    public function testNothingIsRetiredWhenTheSubmissionIsRejected(): void
    {
        $provider = $this->served('rivm-test:tap3', 'node/88803', self::LAT, self::LNG);
        [, $sub] = $this->submitted('node/88803', ItemSource::Osm, 'node/88803', []);

        $this->service->decide((int) $sub->getId(), 'reject', $this->curator, 'Not a tap.');

        $this->em->clear();
        self::assertSame(ItemState::Verified, $this->item($provider)->getState());
    }

    /** The card names the OSM point whose free pin goes, by the rule approval uses. */
    public function testTheCardNamesTheOsmPointTheNewPlaceTakesOver(): void
    {
        $replaced = static::getContainer()->get(ReplacedPlaces::class);

        [$own] = $this->submitted('node/88811', ItemSource::Osm, 'node/88811', []);
        self::assertSame('node/88811', $replaced->takesOsm((int) $own->getId(), 'node/88811', ReplacedPlaces::ticks([])), 'its own point');

        $provider = $this->served('rivm-test:tap-card', 'node/88812', self::LAT + 0.00006, self::LNG);
        [$ticked] = $this->submitted(null, ItemSource::User, 'sub:replaces-card', ['item:'.$provider->getId()]);
        self::assertSame(
            'node/88812',
            $replaced->takesOsm((int) $ticked->getId(), null, ReplacedPlaces::ticks(['item:'.$provider->getId()])),
            'the point of the row it retires',
        );

        [$none] = $this->submitted(null, ItemSource::User, 'sub:replaces-none', []);
        self::assertNull($replaced->takesOsm((int) $none->getId(), null, ReplacedPlaces::ticks([])), 'no point, no line');
    }

    /** The rider who added the replaced place hears why (content-reports.md §7). */
    public function testTheRiderWhoAddedTheReplacedPlaceIsSentTheReasons(): void
    {
        $author = (new User())->setEmail('author@replaces.test');
        $author->setPassword('x');
        $this->em->persist($author);
        $this->em->flush();
        $theirs = $this->served('user:replaces-theirs', null, self::LAT + 0.00006, self::LNG);
        $this->authoredBy($theirs, (int) $author->getId());
        $mine = $this->served('user:replaces-mine', null, self::LAT + 0.00008, self::LNG);
        $this->authoredBy($mine, $this->submitterId);

        [, $sub] = $this->submitted(null, ItemSource::User, 'sub:replaces-told', ['item:'.$theirs->getId(), 'item:'.$mine->getId()]);
        $this->service->decide((int) $sub->getId(), 'approve', $this->curator, null);

        $statements = $this->em->getRepository(UserMessage::class)->findBy(['kind' => UserMessageKind::StatementOfReasons, 'refId' => [$theirs->getId(), $mine->getId()]]);
        self::assertCount(1, $statements, 'replacing your own place tells you nothing');
        self::assertSame($author->getId(), $statements[0]->getUserId());
        $statement = StatementOfReasons::fromArray($statements[0]->getBodyParams()[StatementOfReasons::PARAM] ?? null);
        self::assertNotNull($statement);
        self::assertSame(StatementDecision::Retired, $statement->decision);
        self::assertSame(StatementGround::Duplicate, $statement->ground);
        self::assertSame('dsa_statement.facts.retired_duplicate', $statement->factsKey);
    }

    public function testTicksAreCleanedBeforeUse(): void
    {
        self::assertSame(
            ['items' => [12], 'osm' => ['node/34']],
            ReplacedPlaces::ticks(['item:12', 'node/34', 'item:0', 'item:x', 'drop table', ['item:5'], 'item:12']),
        );
        self::assertSame(['items' => [], 'osm' => []], ReplacedPlaces::ticks('item:1'));
    }

    private function served(string $sourceRef, ?string $osmRef, float $lat, float $lng): Item
    {
        $item = (new Item())->answerOsm($osmRef)->setLetter('B')->setName('Kraan')
            ->setGeom(\sprintf('{"type":"Point","coordinates":[%F,%F]}', $lng, $lat))->setCountryCode('BE')
            ->setState(ItemState::Verified)->setSource(ItemSource::Authority)->setSourceRef($sourceRef)
            ->setAttributes(['type' => 'Drinking tap']);
        $this->em->persist($item);
        $this->em->flush();

        return $item;
    }

    /**
     * @param list<string> $replaces
     *
     * @return array{Item, Submission}
     */
    private function submitted(?string $osmRef, ItemSource $source, string $sourceRef, array $replaces): array
    {
        $geom = \sprintf('{"type":"Point","coordinates":[%F,%F]}', self::LNG, self::LAT);
        $item = (new Item())->answerOsm($osmRef)->setLetter('B')->setName('Kraan')
            ->setGeom($geom)->setCountryCode('BE')
            ->setState(ItemState::Submitted)->setSource($source)->setSourceRef($sourceRef)
            ->setAttributes(['type' => 'Drinking tap']);
        $this->em->persist($item);
        $this->em->flush();
        $sub = (new Submission())->setType(SubmissionType::NewItem)->setLetter('B')->setUserId($this->submitterId)
            ->setItemId($item->getId())->setTitle('Kraan')->setGeom($geom)->setCountryCode('BE')
            ->setChanges([])->setPayload([] === $replaces ? [] : ['_replaces' => $replaces]);
        $this->em->persist($sub);
        $this->em->flush();

        return [$item, $sub];
    }

    /** The approved new-place submission that makes a rider a place's author. */
    private function authoredBy(Item $item, int $userId): void
    {
        $sub = (new Submission())->setType(SubmissionType::NewItem)->setLetter('B')->setUserId($userId)
            ->setItemId($item->getId())->setTitle('Kraan')->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setChanges([])->setPayload([])->setStatus(SubmissionStatus::Approved);
        $this->em->persist($sub);
        $this->em->flush();
    }

    private function item(Item $item): Item
    {
        $fresh = $this->em->find(Item::class, $item->getId());
        self::assertInstanceOf(Item::class, $fresh);

        return $fresh;
    }

    private function history(Item $item, string $field): ?ChangeHistory
    {
        return $this->em->getRepository(ChangeHistory::class)->findOneBy(['itemId' => $item->getId(), 'field' => $field]);
    }
}
