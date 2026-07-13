<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Community;

use App\Catalog\ConfirmationStance;
use App\Catalog\Entity\Item;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Community\ItemConfirmationService;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ItemConfirmationServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ItemConfirmationService $service;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->service = static::getContainer()->get(ItemConfirmationService::class);
    }

    private function user(string $email): User
    {
        $u = (new User())->setEmail($email)->setPassword('x');
        $this->em->persist($u);
        $this->em->flush();

        return $u;
    }

    private function item(string $letter): Item
    {
        $item = (new Item())->setLetter($letter)->setName('Fountain')
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setState(ItemState::Verified)->setSource(ItemSource::Osm)
            ->setSourceRef('node/'.bin2hex(random_bytes(4)))->setAttributes([]);
        $this->em->persist($item);
        $this->em->flush();

        return $item;
    }

    public function testRecordCreatesAConfirmationAndTally(): void
    {
        $water = $this->item('C');
        $this->service->record($water, $this->user('a@t.test'), ConfirmationStance::Potable);

        $snap = $this->service->snapshot($water, null);
        self::assertSame(1, $snap['stances']['potable']);
        self::assertSame(0, $snap['stances']['not_potable']);
        self::assertSame(1, $snap['total']);
    }

    public function testStanceIsOnePerUserAndSwitchable(): void
    {
        $water = $this->item('C');
        $u = $this->user('b@t.test');
        $this->service->record($water, $u, ConfirmationStance::Potable);
        $this->service->record($water, $u, ConfirmationStance::NotPotable);

        $snap = $this->service->snapshot($water, $u);
        self::assertSame(0, $snap['stances']['potable']);
        self::assertSame(1, $snap['stances']['not_potable']);
        self::assertSame('not_potable', $snap['mine']);
        self::assertSame(1, $snap['total'], 'switching must not create a second row');
    }

    public function testTallyCountsAcrossUsers(): void
    {
        $water = $this->item('C');
        $this->service->record($water, $this->user('c1@t.test'), ConfirmationStance::Potable);
        $this->service->record($water, $this->user('c2@t.test'), ConfirmationStance::Potable);
        $this->service->record($water, $this->user('c3@t.test'), ConfirmationStance::NotPotable);

        $snap = $this->service->snapshot($water, null);
        self::assertSame(2, $snap['stances']['potable']);
        self::assertSame(1, $snap['stances']['not_potable']);
        self::assertSame(3, $snap['total']);
        self::assertNull($snap['mine'], 'anonymous snapshot has no personal stance');
    }

    public function testExistenceConfirmationForAUtility(): void
    {
        $services = $this->item('D');
        $this->service->record($services, $this->user('d@t.test'), ConfirmationStance::Exists);

        $snap = $this->service->snapshot($services, null);
        self::assertSame(1, $snap['stances']['exists']);
        self::assertArrayNotHasKey('potable', $snap['stances'], 'a utility only exposes its own stance keys');
    }

    public function testRejectsStanceNotAllowedForType(): void
    {
        $water = $this->item('C');
        $this->expectException(\InvalidArgumentException::class);
        $this->service->record($water, $this->user('e@t.test'), ConfirmationStance::Exists);
    }

    public function testRejectsPotableOnANonWaterUtility(): void
    {
        $services = $this->item('D');
        $this->expectException(\InvalidArgumentException::class);
        $this->service->record($services, $this->user('f@t.test'), ConfirmationStance::Potable);
    }
}
