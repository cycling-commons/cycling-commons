<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Contribution;

use App\Catalog\Entity\Region;
use App\Catalog\Entity\Submission;
use App\Contribution\BikeWayLocator;
use App\Contribution\BikeWayReading;
use App\Entity\User;
use App\Service\ContributionStubInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Adding a scenic view more than 250 m from a way a bike may ride
 * (docs/specs/scenic-views.md, "Adding a scenic view").
 *
 * The form warns and the rider may overrule it: they know the spot, and
 * overruling tells the curator it is reachable. What the service guarantees is
 * that the overrule is a decision on record, never a silent default, and that
 * a place added from a ride (Scout) is recorded rather than refused.
 */
final class ScenicBikeWayIntakeTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private function service(BikeWayReading $reading): ContributionStubInterface
    {
        self::bootKernel();
        static::getContainer()->set(BikeWayLocator::class, new class($reading) implements BikeWayLocator {
            public function __construct(private readonly BikeWayReading $reading)
            {
            }

            #[\Override]
            public function nearest(float $lat, float $lng): BikeWayReading
            {
                return $this->reading;
            }
        });
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $region = (new Region())->setSlug('wallonia')->setName('Wallonia')->setCountryCode('BE')
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[4.0,49.5],[6.5,49.5],[6.5,51.0],[4.0,51.0],[4.0,49.5]]]]}');
        $this->em->persist($region);
        $this->em->flush();

        return static::getContainer()->get(ContributionStubInterface::class);
    }

    private function rider(): User
    {
        $user = (new User())->setEmail('scenic-rider-'.uniqid('', true).'@test.test');
        $user->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** @return array<string, mixed> */
    private static function view(array $extra = []): array
    {
        return [
            'type' => 'scenic-views',
            'details' => ['name' => 'Uitzicht op de heuvel'],
            'lat' => '50.47', 'lng' => '5.86', 'place' => 'Testville',
        ] + $extra;
    }

    public function testAFarViewFromTheFormIsRefusedUntilTheRiderOverrules(): void
    {
        $service = $this->service(new BikeWayReading(true, 1300.0));

        try {
            $service->submit('add', self::view(['bikewayOverride' => '']), $this->rider());
            self::fail('a far view from the form must ask the rider first');
        } catch (ValidationFailedException $e) {
            self::assertSame('contribute.error.far_from_bike_way', $e->getViolations()[0]->getMessage());
        }
    }

    public function testAnOverruledFarViewIsFiledWithTheDistanceOnRecord(): void
    {
        $service = $this->service(new BikeWayReading(true, 1300.0));

        $receipt = $service->submit('add', self::view(['bikewayOverride' => '1']), $this->rider());

        $sub = $this->em->find(Submission::class, $receipt->submissionId);
        self::assertNotNull($sub);
        self::assertSame(
            ['known' => true, 'nearestM' => 1300, 'withinM' => 250, 'far' => true, 'overruled' => true],
            $sub->getPayload()['_bikeway'],
        );
    }

    public function testANearViewNeedsNoOverruleAndSaysSo(): void
    {
        $service = $this->service(new BikeWayReading(true, 40.0));

        $receipt = $service->submit('add', self::view(['bikewayOverride' => '']), $this->rider());

        $sub = $this->em->find(Submission::class, $receipt->submissionId);
        self::assertNotNull($sub);
        self::assertFalse($sub->getPayload()['_bikeway']['far']);
        self::assertFalse($sub->getPayload()['_bikeway']['overruled']);
    }

    public function testAViewAddedFromARideIsRecordedNotRefused(): void
    {
        // Scout sends no bikewayOverride: the rider was on the bike, and there is no form to ask them in.
        $service = $this->service(new BikeWayReading(true, 900.0));

        $receipt = $service->submit('add', self::view(['via' => 'scout']), $this->rider());

        $sub = $this->em->find(Submission::class, $receipt->submissionId);
        self::assertNotNull($sub);
        self::assertTrue($sub->getPayload()['_bikeway']['far']);
    }

    public function testWhenTheRouterCannotBeAskedNobodyIsRefused(): void
    {
        $service = $this->service(BikeWayReading::unknown());

        $receipt = $service->submit('add', self::view(['bikewayOverride' => '']), $this->rider());

        self::assertTrue($receipt->persisted);
    }

    public function testOtherTypesAreNotAsked(): void
    {
        $service = $this->service(new BikeWayReading(true, 5000.0));

        $receipt = $service->submit('add', [
            'type' => 'water-food',
            'details' => ['name' => 'Kraan'],
            'lat' => '50.47', 'lng' => '5.86', 'place' => 'Testville',
            'bikewayOverride' => '',
        ], $this->rider());

        $sub = $this->em->find(Submission::class, $receipt->submissionId);
        self::assertNotNull($sub);
        self::assertArrayNotHasKey('_bikeway', $sub->getPayload());
    }
}
