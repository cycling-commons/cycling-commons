<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Community;

use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RouteRideItTest extends WebTestCase
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

    private function unverifiedRoute(EntityManagerInterface $em, int $proposerId): RecommendedRoute
    {
        $r = (new RecommendedRoute())->setName('Proposed · Condroz')
            ->setGeom('{"type":"LineString","coordinates":[[5.2,50.4],[5.3,50.5]]}')
            ->setDistanceM(20000)->setState(ItemState::Unverified)
            ->setSource(ItemSource::User)->setSourceRef('user:ride-'.uniqid())->setRegionId(1)->setProposedBy($proposerId);
        $em->persist($r);
        $em->flush();

        return $r;
    }

    /** Fetches a valid stateless CSRF token the same way the drawer does. */
    private function token(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, int $routeId): string
    {
        $client->request('GET', '/routes/'.$routeId.'/community');

        return json_decode((string) $client->getResponse()->getContent(), true)['token'];
    }

    public function testThirdIndependentRideFlipsToVerified(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $proposer = $this->rider($em, 'prop@test.test');
        $route = $this->unverifiedRoute($em, $proposer->getId());
        $rid = $route->getId();

        // Proposer rides first — must NOT count (P3-D1).
        $client->loginUser($proposer);
        $client->request('POST', '/routes/'.$rid.'/rode-it', ['bike_type' => 'Gravel', '_token' => $this->token($client, $rid)]);
        self::assertResponseIsSuccessful();

        // Three independent riders → flip on the third.
        $tippingRiderId = null;
        foreach (['a@test.test', 'b@test.test', 'c@test.test'] as $i => $email) {
            $u = $this->rider($em, $email);
            $client->loginUser($u);
            $client->request('POST', '/routes/'.$rid.'/rode-it', ['bike_type' => 'Road', '_token' => $this->token($client, $rid)]);
            self::assertResponseIsSuccessful();
            $data = json_decode((string) $client->getResponse()->getContent(), true);
            $expected = $i < 2 ? 'unverified' : 'verified';   // flips exactly at the 3rd
            self::assertSame($expected, $data['state'], 'after rider #'.($i + 1));
            if (2 === $i) {
                $tippingRiderId = $u->getId();   // the rider whose ride crossed the threshold
            }
        }

        $em->clear();
        self::assertSame(ItemState::Verified, $em->find(RecommendedRoute::class, $rid)->getState());
        // The flip logged one state row attributed to the tipping rider (P3-D2).
        $rows = $em->getConnection()->fetchAllAssociative(
            "SELECT new_value, changed_by FROM route_change_history WHERE route_id = :r AND field = 'state'",
            ['r' => $rid],
        );
        self::assertCount(1, $rows);
        self::assertSame('"verified"', $rows[0]['new_value']);   // JSONB-encoded
        self::assertSame($tippingRiderId, (int) $rows[0]['changed_by']);   // attributed to the 3rd rider (P3-D2)
    }

    public function testResubmitIsIdempotent(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $route = $this->unverifiedRoute($em, 9);
        $rid = $route->getId();
        $u = $this->rider($em, 'dup@test.test');

        $client->loginUser($u);
        $client->request('POST', '/routes/'.$rid.'/rode-it', ['bike_type' => 'Road', '_token' => $this->token($client, $rid)]);
        $client->request('POST', '/routes/'.$rid.'/rode-it', ['bike_type' => 'MTB', '_token' => $this->token($client, $rid)]);
        self::assertResponseIsSuccessful();

        self::assertSame(1, (int) $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM route_ride WHERE route_id = :r AND user_id = :u',
            ['r' => $rid, 'u' => $u->getId()],
        ));
    }

    public function testBadCsrfIsRejected(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $route = $this->unverifiedRoute($em, 9);
        $u = $this->rider($em, 'csrf@test.test');

        $client->loginUser($u);
        $client->request('POST', '/routes/'.$route->getId().'/rode-it', ['bike_type' => 'Road', '_token' => 'wrong']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testInvalidBikeTypeIs422(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $route = $this->unverifiedRoute($em, 9);
        $rid = $route->getId();
        $u = $this->rider($em, 'bike@test.test');

        $client->loginUser($u);
        $client->request('POST', '/routes/'.$rid.'/rode-it', ['bike_type' => 'Unicycle', '_token' => $this->token($client, $rid)]);
        self::assertResponseStatusCodeSame(422);
    }
}
