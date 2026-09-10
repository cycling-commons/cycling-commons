<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Community;

use App\Catalog\BikeType;
use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\Entity\RouteRide;
use App\Catalog\Entity\RouteVote;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\Season;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RouteCommunityReadTest extends WebTestCase
{
    private function rider(EntityManagerInterface $em, string $email): User
    {
        $u = (new User())->setEmail($email)->setDisplayName(strstr($email, '@', true) ?: $email);
        $u->setEmailVerified(true)->setEmailVerifiedAt(new \DateTimeImmutable())->setRoles([]);
        $u->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($u, 'password1234'));
        $em->persist($u);
        $em->flush();

        return $u;
    }

    private function route(EntityManagerInterface $em, ItemState $state, ?int $proposedBy): RecommendedRoute
    {
        $r = (new RecommendedRoute())->setName('Community loop · Condroz')
            ->setGeom('{"type":"LineString","coordinates":[[5.2,50.4],[5.3,50.5]]}')
            ->setDistanceM(20000)->setState($state)
            ->setSource(ItemSource::User)->setSourceRef('user:comm-'.uniqid())->setRegionId(1)->setProposedBy($proposedBy);
        $em->persist($r);
        $em->flush();

        return $r;
    }

    public function testAnonymousIsChallenged(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $route = $this->route($em, ItemState::Verified, null);

        $client->request('GET', '/routes/'.$route->getId().'/community');
        self::assertResponseStatusCodeSame(401);
    }

    public function testSnapshotCountsIndependentRidesAndMyState(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $me = $this->rider($em, 'me@test.test');
        $proposer = $this->rider($em, 'prop@test.test');
        $route = $this->route($em, ItemState::Unverified, $proposer->getId());

        // Proposer's own ride does NOT count toward the threshold (P3-D1); my ride does.
        $em->persist(new RouteRide($route->getId(), $proposer->getId(), BikeType::Gravel));
        $em->persist(new RouteRide($route->getId(), $me->getId(), BikeType::Road));
        $em->flush();

        $client->loginUser($me);
        $client->request('GET', '/routes/'.$route->getId().'/community');
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('unverified', $data['state']);
        self::assertSame(1, $data['rideCount']);   // proposer excluded
        self::assertSame(3, $data['threshold']);
        self::assertTrue($data['iRode']);
        self::assertFalse($data['iVotedThisSeason']);
        self::assertIsString($data['token']);
        self::assertNotSame('', $data['token']);
    }

    public function testMyVoteThisSeasonIsReflected(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $me = $this->rider($em, 'voter@test.test');
        $route = $this->route($em, ItemState::Verified, null);
        $season = Season::current(new \DateTimeImmutable());
        $em->persist(new RouteVote($route->getId(), $me->getId(), $season, BikeType::Road));
        $em->flush();

        $client->loginUser($me);
        $client->request('GET', '/routes/'.$route->getId().'/community');
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(1, $data['voteCount']);
        self::assertTrue($data['iVotedThisSeason']);
    }

    public function testUnservedRouteIs404(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $me = $this->rider($em, 'x@test.test');
        $route = $this->route($em, ItemState::Submitted, null);

        $client->loginUser($me);
        $client->request('GET', '/routes/'.$route->getId().'/community');
        self::assertResponseStatusCodeSame(404);
    }
}
