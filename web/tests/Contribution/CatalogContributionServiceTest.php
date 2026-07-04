<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Contribution;

use App\Catalog\Entity\Item;
use App\Catalog\Entity\Region;
use App\Catalog\Entity\Submission;
use App\Catalog\ItemState;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use App\Entity\User;
use App\Service\ContributionStubInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CatalogContributionServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ContributionStubInterface $service;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->service = static::getContainer()->get(ContributionStubInterface::class);
    }

    private function user(): User
    {
        $user = (new User())->setEmail('contrib@test.test');
        // Mirror the seeding pattern used in existing Auth tests (password not
        // relevant here; check tests/Auth for the minimal required setters).
        $user->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function wallonia(): Region
    {
        $region = (new Region())->setSlug('wallonia')->setName('Wallonia')->setCountryCode('BE')
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[4.0,49.5],[6.5,49.5],[6.5,51.0],[4.0,51.0],[4.0,49.5]]]]}');
        $this->em->persist($region);
        $this->em->flush();

        return $region;
    }

    public function testClimbSubmissionCreatesItemAndSubmissionAtomically(): void
    {
        $region = $this->wallonia();
        $receipt = $this->service->submit('climb', [
            'fName' => 'Côte du Test', 'fLen' => 3.1, 'fGain' => 200, 'fAvg' => '6.4',
            'fMax' => 12, 'fSurface' => 'Asphalt', 'fNote' => 'Steady, honest climb.',
            'lat' => '50.47', 'lng' => '5.86', 'place' => 'Testville',
        ], $this->user());

        self::assertTrue($receipt->persisted);
        self::assertNotNull($receipt->submissionId);

        $sub = $this->em->find(Submission::class, $receipt->submissionId);
        self::assertNotNull($sub);
        self::assertSame(SubmissionType::NewItem, $sub->getType());
        self::assertSame(SubmissionStatus::Pending, $sub->getStatus());
        self::assertSame('B', $sub->getLetter());
        self::assertSame('BE', $sub->getCountryCode());
        self::assertSame($region->getId(), $sub->getRegionId());
        self::assertNotNull($sub->getItemId());

        $item = $this->em->find(Item::class, $sub->getItemId());
        self::assertNotNull($item);
        self::assertSame(ItemState::Submitted, $item->getState());
        self::assertSame('sub:'.$sub->getId(), $item->getSourceRef());
        self::assertSame('Côte du Test', $item->getName());
    }

    public function testVoteStaysUnpersisted(): void
    {
        $receipt = $this->service->submit('vote', ['choice' => 'x'], $this->user());
        self::assertFalse($receipt->persisted);
        self::assertNull($receipt->submissionId);
        self::assertSame(0, $this->em->getRepository(Submission::class)->count([]));
    }

    public function testPointOutsideAnyRegionGetsEmptyCountry(): void
    {
        $receipt = $this->service->submit('climb', [
            'fName' => 'Nowhere climb', 'lat' => '10.0', 'lng' => '10.0',
        ], $this->user());
        $sub = $this->em->find(Submission::class, $receipt->submissionId);
        self::assertSame('', $sub->getCountryCode());
        self::assertNull($sub->getRegionId());
    }

    public function testSubmittedItemsAreNotServed(): void
    {
        // Spec §8: state=submitted never reaches /map/catalog.json.
        $this->wallonia();
        $this->service->submit('climb', [
            'fName' => 'Côte invisible', 'lat' => '50.47', 'lng' => '5.86',
        ], $this->user());

        $provider = static::getContainer()->get(\App\Catalog\CatalogProvider::class);
        self::assertStringNotContainsString('Côte invisible', $provider->json());
    }
}
