<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Community;

use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RouteVoteItTest extends WebTestCase
{
    private function rider(EntityManagerInterface $em, string $email): User
    {
        $u = (new User())->setEmail($email)->setDisplayName('R');
        $u->setEmailVerified(true)->setEmailVerifiedAt(new \DateTimeImmutable())->setRoles([]);
        $u->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($u, 'password1234'));
        $em->persist($u);
        $em->flush();

        return $u;
    }

    private function route(EntityManagerInterface $em, ItemState $state): RecommendedRoute
    {
        $r = (new RecommendedRoute())->setName('Votable · Condroz')
            ->setGeom('{"type":"LineString","coordinates":[[5.2,50.4],[5.3,50.5]]}')
            ->setDistanceM(20000)->setState($state)
            ->setSource(ItemSource::User)->setSourceRef('user:vote-'.uniqid())->setRegionId(1)->setProposedBy(9);
        $em->persist($r);
        $em->flush();

        return $r;
    }

    private function token(KernelBrowser $client, int $routeId): string
    {
        $client->request('GET', '/routes/'.$routeId.'/community');

        return json_decode((string) $client->getResponse()->getContent(), true)['token'];
    }

    public function testVoteOnVerifiedRouteIsCounted(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $route = $this->route($em, ItemState::Verified);
        $rid = $route->getId();
        $u = $this->rider($em, 'v1@test.test');

        $client->loginUser($u);
        $client->request('POST', '/routes/'.$rid.'/vote', ['season' => 'spring', 'bike_type' => 'Gravel', '_token' => $this->token($client, $rid)]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(1, $data['voteCount']);
    }

    public function testSecondVoteSameSeasonIsIdempotent(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $route = $this->route($em, ItemState::Verified);
        $rid = $route->getId();
        $u = $this->rider($em, 'v2@test.test');

        $client->loginUser($u);
        $client->request('POST', '/routes/'.$rid.'/vote', ['season' => 'spring', 'bike_type' => 'Gravel', '_token' => $this->token($client, $rid)]);
        $client->request('POST', '/routes/'.$rid.'/vote', ['season' => 'spring', 'bike_type' => 'Road', '_token' => $this->token($client, $rid)]);
        self::assertResponseIsSuccessful();

        self::assertSame(1, (int) $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM route_vote WHERE route_id = :r AND user_id = :u',
            ['r' => $rid, 'u' => $u->getId()],
        ));
    }

    public function testVoteOnUnverifiedRouteIsRejected(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $route = $this->route($em, ItemState::Unverified);
        $rid = $route->getId();
        $u = $this->rider($em, 'v3@test.test');

        // Token comes from the community endpoint (which serves unverified too)…
        $client->loginUser($u);
        $token = $this->token($client, $rid);
        // …but voting is gated to verified routes (spec D7): 404.
        $client->request('POST', '/routes/'.$rid.'/vote', ['season' => 'spring', 'bike_type' => 'Gravel', '_token' => $token]);
        self::assertResponseStatusCodeSame(404);

        self::assertSame(0, (int) $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM route_vote WHERE route_id = :r',
            ['r' => $rid],
        ));
    }
}
