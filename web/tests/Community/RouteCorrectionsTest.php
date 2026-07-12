<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Community;

use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\Entity\RouteSuggestion;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\RouteSuggestionReason;
use App\Catalog\RouteSuggestionStatus;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RouteCorrectionsTest extends WebTestCase
{
    private function user(EntityManagerInterface $em, string $email, array $roles = []): User
    {
        $u = (new User())->setEmail($email)->setDisplayName('U');
        $u->setEmailVerified(true)->setEmailVerifiedAt(new \DateTimeImmutable())->setRoles($roles);
        if ([] !== $roles) {
            $u->setTotpSecret('JBSWY3DPEHPK3PXP')->setTwoFaEnabled(true);
        }
        $u->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($u, 'password1234'));
        $em->persist($u);
        $em->flush();

        return $u;
    }

    private function route(EntityManagerInterface $em): RecommendedRoute
    {
        $r = (new RecommendedRoute())->setName('Located · Condroz')
            ->setGeom('{"type":"LineString","coordinates":[[5.2,50.4],[5.3,50.5]]}')
            ->setDistanceM(20000)->setState(ItemState::Verified)
            ->setSource(ItemSource::User)->setSourceRef('user:loc-'.uniqid())->setRegionId(1)->setProposedBy(9);
        $em->persist($r);
        $em->flush();

        return $r;
    }

    private function token(KernelBrowser $client, int $routeId): string
    {
        $client->request('GET', '/routes/'.$routeId.'/community');

        return json_decode((string) $client->getResponse()->getContent(), true)['token'];
    }

    /**
     * Postgres JSONB reorders object keys on write (Task 1's caveat): assert
     * on the [start, end] VALUES, key-order-agnostic, not on raw key order.
     *
     * @return list<array{0: float, 1: float}>
     */
    private function normalizeSegments(array $segments): array
    {
        return array_map(static fn (array $s): array => [$s['start'], $s['end']], $segments);
    }

    public function testSuggestStoresSegments(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $route = $this->route($em);
        $rid = $route->getId();
        $u = $this->user($em, 'seg@test.test');

        $client->loginUser($u);
        $client->request('POST', '/routes/'.$rid.'/suggest', [
            'reason' => 'broken-track', 'note' => 'gate',
            'segments' => json_encode([['start' => 0.1, 'end' => 0.3]]),
            '_token' => $this->token($client, $rid),
        ]);
        self::assertResponseIsSuccessful();

        $rows = $em->getConnection()->fetchAllAssociative('SELECT segments FROM route_suggestion WHERE route_id = :r', ['r' => $rid]);
        self::assertCount(1, $rows);
        $decoded = json_decode((string) $rows[0]['segments'], true);
        self::assertSame([[0.1, 0.3]], $this->normalizeSegments($decoded));
    }

    public function testInvalidSegmentsRejected(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $route = $this->route($em);
        $rid = $route->getId();
        $u = $this->user($em, 'badseg@test.test');
        $client->loginUser($u);

        // end < start → 422, nothing stored
        $client->request('POST', '/routes/'.$rid.'/suggest', [
            'reason' => 'other',
            'segments' => json_encode([['start' => 0.8, 'end' => 0.2]]),
            '_token' => $this->token($client, $rid),
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM route_suggestion WHERE route_id = :r', ['r' => $rid]));

        // a JSON object (non-list, e.g. {"foo":{...}}) → 422, not silently accepted as a list
        $client->request('POST', '/routes/'.$rid.'/suggest', [
            'reason' => 'other',
            'segments' => json_encode(['foo' => ['start' => 0.1, 'end' => 0.3]]),
            '_token' => $this->token($client, $rid),
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM route_suggestion WHERE route_id = :r', ['r' => $rid]));
    }

    public function testCorrectionsEndpointCuratorOnlyPending(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $route = $this->route($em);
        $rid = $route->getId();

        // one pending (with segments) + one resolved
        $em->persist(new RouteSuggestion($rid, 5, RouteSuggestionReason::BrokenTrack, 'p', [['start' => 0.2, 'end' => 0.4]]));
        $done = new RouteSuggestion($rid, 6, RouteSuggestionReason::Other, 'd', [['start' => 0.5, 'end' => 0.6]]);
        $done->resolve(RouteSuggestionStatus::Done, 7);
        $em->persist($done);
        $em->flush();

        // a plain user is forbidden
        $rider = $this->user($em, 'plain@test.test');
        $client->loginUser($rider);
        $client->request('GET', '/routes/'.$rid.'/corrections');
        self::assertResponseStatusCodeSame(403);

        // a curator gets only the pending one, with its segments
        $curator = $this->user($em, 'cur@test.test', ['ROLE_CURATOR']);
        $client->loginUser($curator);
        $client->request('GET', '/routes/'.$rid.'/corrections');
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertCount(1, $data['corrections']);
        self::assertSame('broken-track', $data['corrections'][0]['reason']);
        self::assertSame([[0.2, 0.4]], $this->normalizeSegments($data['corrections'][0]['segments']));
    }
}
