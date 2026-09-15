<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * `?route=<id>` for a route waiting for review (docs/specs/map-and-search.md §8).
 * The catalog payload serves only live routes, so the Routes desk's "Open this
 * route on the map" link for a submitted route found nothing and the map stayed
 * on the scope. The page now carries that one route as CC_ROUTE_PREVIEW, for a
 * curator who may moderate it and for the rider who proposed it, and for nobody
 * else.
 */
final class MapRoutePreviewTest extends WebTestCase
{
    /** @param list<string> $roles */
    private function login(KernelBrowser $client, string $email, array $roles, bool $curator): User
    {
        $container = static::getContainer();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Route Preview User');
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles($roles);
        $user->setPassword($hasher->hashPassword($user, 'hunter2secure!'));
        if ($curator) {
            $user->setTotpSecret('JBSWY3DPEHPK3PXP');
            $user->setTwoFaEnabled(true);
        }
        $em->persist($user);
        $em->flush();
        $client->loginUser($user);

        return $user;
    }

    private function seedRoute(string $name, string $state, ?int $proposedBy = null): int
    {
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $db->executeStatement(
            "INSERT INTO recommended_route (name, geom, distance_m, ascent_m, state, source, source_ref, attributes, proposed_by, created_at, updated_at)
             VALUES (:name, ST_SetSRID(ST_GeomFromText('LINESTRING(5.57 50.63, 5.72 50.00, 5.57 50.63)'), 4326), 249392, 4200, :state, 'user', :ref, '{}', :by, NOW(), NOW())",
            ['name' => $name, 'state' => $state, 'ref' => 'user:preview-'.bin2hex(random_bytes(6)), 'by' => $proposedBy],
        );

        return (int) $db->fetchOne('SELECT MAX(id) FROM recommended_route');
    }

    /** @return array<string, mixed>|null */
    private function preview(KernelBrowser $client): ?array
    {
        $body = (string) $client->getResponse()->getContent();
        if (1 !== preg_match('~window\.CC_ROUTE_PREVIEW = (.*?);\n~', $body, $m)) {
            return null;
        }
        /** @var array<string, mixed>|null $data */
        $data = json_decode($m[1], true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }

    public function testCuratorGetsTheSubmittedRouteTheLinkNames(): void
    {
        $client = static::createClient();
        $this->login($client, 'route-preview-curator@example.com', ['ROLE_CURATOR'], true);
        $id = $this->seedRoute('Liege Bastogne Liege Preview', 'submitted');

        $client->request('GET', '/map?route='.$id);

        self::assertResponseIsSuccessful();
        $route = $this->preview($client);
        self::assertNotNull($route, 'the page carries the route the link points at');
        self::assertSame($id, $route['id']);
        self::assertSame('Liege Bastogne Liege Preview', $route['name']);
        self::assertSame('submitted', $route['state']);
        self::assertEquals([[50.63, 5.57], [50.0, 5.72], [50.63, 5.57]], $route['loop']);
        self::assertEqualsWithDelta(249.392, $route['km'], 0.0001);
    }

    public function testTheRiderWhoProposedItGetsItAndAnotherRiderDoesNot(): void
    {
        $client = static::createClient();
        $me = $this->login($client, 'route-preview-proposer@example.com', [], false);
        $mine = $this->seedRoute('My Own Proposed Loop', 'submitted', (int) $me->getId());
        $theirs = $this->seedRoute('Somebody Elses Loop', 'submitted', null);

        $client->request('GET', '/map?route='.$mine);
        self::assertSame('My Own Proposed Loop', $this->preview($client)['name'] ?? null);

        $client->request('GET', '/map?route='.$theirs);
        self::assertNull($this->preview($client));
        self::assertStringNotContainsString('Somebody Elses Loop', (string) $client->getResponse()->getContent());
    }

    public function testAnonymousNeverGetsASubmittedRoute(): void
    {
        $client = static::createClient();
        $id = $this->seedRoute('Hidden Submitted Loop', 'submitted');

        $client->request('GET', '/map?route='.$id);

        self::assertResponseIsSuccessful();
        self::assertNull($this->preview($client));
        self::assertStringNotContainsString('Hidden Submitted Loop', (string) $client->getResponse()->getContent());
    }

    public function testALiveRouteOrNoRouteParamCarriesNoPreview(): void
    {
        $client = static::createClient();
        $this->login($client, 'route-preview-curator2@example.com', ['ROLE_CURATOR'], true);
        $live = $this->seedRoute('Live Loop', 'unverified');
        $rejected = $this->seedRoute('Rejected Loop', 'rejected');

        $client->request('GET', '/map?route='.$live);
        self::assertNull($this->preview($client), 'a live route is already in the catalog payload');
        $client->request('GET', '/map?route='.$rejected);
        self::assertNull($this->preview($client), 'only a route waiting for review is previewed');
        $client->request('GET', '/map');
        self::assertNull($this->preview($client));
    }
}
