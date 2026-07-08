<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Contribution;

use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Contribution\RouteProposalService;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final class RouteProposalServiceTest extends KernelTestCase
{
    /** A straight ~11 km meridian track, one point every ~111 m, with elevation. */
    private static function gpx(): string
    {
        $pts = '';
        for ($i = 0; $i <= 100; ++$i) {
            $pts .= sprintf('<trkpt lat="%.4f" lon="5.3000"><ele>%d</ele></trkpt>', 50.0 + $i * 0.001, 100 + $i);
        }

        return '<?xml version="1.0"?><gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1">'
            .'<trk><trkseg>'.$pts.'</trkseg></trk></gpx>';
    }

    private function makeUser(string $email): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail($email);
        $user->setPassword('x');
        $em->persist($user);
        $em->flush();

        return $user;
    }

    public function testProposePersistsATrimmedSubmittedRoute(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $service = static::getContainer()->get(RouteProposalService::class);
        $user = $this->makeUser('proposer@test.test');

        $route = $service->propose(self::gpx(), [
            'rName' => 'Condroz rollers test',
            'difficulty' => 'Moderate',
            'season' => 'Summer',
            'dominantSurface' => 'Asphalt',
            'note' => 'Rolling hills, quiet lanes.',
            'bikeTypes' => ['Road', 'Gravel'],
            'gradientLimited' => '',
        ], $user);

        self::assertSame(ItemState::Submitted, $route->getState());
        self::assertSame(ItemSource::User, $route->getSource());
        self::assertStringStartsWith('user:', $route->getSourceRef());
        self::assertSame($user->getId(), $route->getProposedBy());
        self::assertSame('Condroz rollers test', $route->getName());
        self::assertSame(['Road', 'Gravel'], $route->getAttributes()['bikeTypes']);
        self::assertArrayNotHasKey('gradientLimited', $route->getAttributes(), 'empty fields are not stored');

        // Privacy trim (D4): stored geometry starts ≥350 m inside the upload.
        $geom = json_decode((string) $route->getGeom(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('LineString', $geom['type']);
        [$lng0, $lat0] = $geom['coordinates'][0];
        self::assertGreaterThan(50.003, $lat0, 'first stored point is ≥~350m inside the raw track');
        self::assertSame(5.3, $lng0);

        // Distance/ascent computed on the trimmed track: raw ≈11.1 km minus
        // 2×(350..750) m → somewhere in ~9.6–10.5 km; ascent ≈ 1 m per 111 m.
        self::assertGreaterThan(9_500, $route->getDistanceM());
        self::assertLessThan(10_500, $route->getDistanceM());
        self::assertNotNull($route->getAscentM());
        self::assertGreaterThan(80, $route->getAscentM());
    }

    public function testRejectsTracksShorterThanTwoKm(): void
    {
        self::bootKernel();
        $service = static::getContainer()->get(RouteProposalService::class);
        $user = $this->makeUser('proposer-short@test.test');

        $short = '<?xml version="1.0"?><gpx version="1.1"><trk><trkseg>'
            .'<trkpt lat="50.0" lon="5.3"/><trkpt lat="50.005" lon="5.3"/>'
            .'</trkseg></trk></gpx>';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('propose_route.error.length_range');
        $service->propose($short, ['rName' => 'Too short'], $user);
    }

    public function testRejectsAProposalWithABlankName(): void
    {
        self::bootKernel();
        $service = static::getContainer()->get(RouteProposalService::class);
        $user = $this->makeUser('proposer-noname@test.test');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('propose_route.error.name_required');
        $service->propose(self::gpx(), ['rName' => '   '], $user);
    }

    public function testFourthProposalInADayIsRateLimited(): void
    {
        self::bootKernel();
        $service = static::getContainer()->get(RouteProposalService::class);
        $user = $this->makeUser('proposer-limit@test.test');

        for ($i = 1; $i <= 3; ++$i) {
            $service->propose(self::gpx(), ['rName' => 'Proposal '.$i], $user);
        }

        $this->expectException(TooManyRequestsHttpException::class);
        $service->propose(self::gpx(), ['rName' => 'Proposal 4'], $user);
    }
}
