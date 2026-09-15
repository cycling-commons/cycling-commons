<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * GET /map/route/{id}/climbs (docs/specs/route-domain.md §6.4): public and cached
 * for a live route; for a route waiting for review, only for whoever may
 * preview it (the `?route=` gate, docs/specs/map-and-search.md §8), never cached.
 */
final class RouteClimbsEndpointTest extends WebTestCase
{
    private function login(KernelBrowser $client, string $email): User
    {
        $container = static::getContainer();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Route Climbs Rider');
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setPassword($hasher->hashPassword($user, 'hunter2secure!'));
        $em->persist($user);
        $em->flush();
        $client->loginUser($user);

        return $user;
    }

    private function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    private function seedRoute(string $state, ?int $proposedBy = null): int
    {
        $this->db()->executeStatement(
            "INSERT INTO recommended_route (name, geom, distance_m, ascent_m, state, source, source_ref, attributes, proposed_by, created_at, updated_at)
             VALUES ('Endpoint route', ST_SetSRID(ST_GeomFromText('LINESTRING(5.80 50.40, 5.83 50.40)'), 4326), 2130, 0, :state, 'user', :ref, '{}', :by, NOW(), NOW())",
            ['state' => $state, 'ref' => 'user:climbs-'.bin2hex(random_bytes(6)), 'by' => $proposedBy],
        );

        return (int) $this->db()->fetchOne('SELECT MAX(id) FROM recommended_route');
    }

    private function seedClimb(): int
    {
        $this->db()->executeStatement(
            "INSERT INTO item (letter, name, geom, country_code, state, source, source_ref, attributes, created_at, updated_at)
             VALUES ('N', 'Endpoint climb', ST_SetSRID(ST_MakePoint(5.805, 50.40), 4326), 'BE', 'verified', 'manual', :ref,
                     '{\"route\": [[50.40, 5.805], [50.40, 5.810]], \"avgGradient\": \"7.2%\"}', NOW(), NOW())",
            ['ref' => 'climb-endpoint-'.bin2hex(random_bytes(6))],
        );

        return (int) $this->db()->fetchOne('SELECT MAX(id) FROM item');
    }

    /** @return array{climbs: list<array<string, mixed>>} */
    private function body(KernelBrowser $client): array
    {
        /** @var array{climbs: list<array<string, mixed>>} $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $body;
    }

    public function testALiveRouteAnswersAnyoneAndIsCachedPublicly(): void
    {
        $client = static::createClient();
        $climb = $this->seedClimb();
        $route = $this->seedRoute('unverified');

        $client->request('GET', '/map/route/'.$route.'/climbs');

        self::assertResponseIsSuccessful();
        $climbs = $this->body($client)['climbs'];
        self::assertSame([$climb], array_column($climbs, 'id'));
        self::assertSame('7.2%', $climbs[0]['avgGradient']);
        $cache = (string) $client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('public', $cache);
    }

    public function testARouteWaitingForReviewAnswersOnlyTheRiderWhoProposedIt(): void
    {
        $client = static::createClient();
        $this->seedClimb();
        $me = $this->login($client, 'route-climbs-proposer@example.com');
        $mine = $this->seedRoute('submitted', (int) $me->getId());
        $theirs = $this->seedRoute('submitted');

        $client->request('GET', '/map/route/'.$mine.'/climbs');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->body($client)['climbs']);
        $cache = (string) $client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cache);
        self::assertStringContainsString('private', $cache);

        $client->request('GET', '/map/route/'.$theirs.'/climbs');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnonymousGetsNothingForAWaitingRejectedOrMissingRoute(): void
    {
        $client = static::createClient();
        $waiting = $this->seedRoute('submitted');
        $rejected = $this->seedRoute('rejected');

        $client->request('GET', '/map/route/'.$waiting.'/climbs');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/map/route/'.$rejected.'/climbs');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/map/route/999999999/climbs');
        self::assertResponseStatusCodeSame(404);
    }
}
