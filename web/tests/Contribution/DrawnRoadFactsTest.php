<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Contribution;

use App\Contribution\RoadWayLocator;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * The add form reads the road a drawn line follows (edit-items/A-road-surface.md, "The form reads the road it
 * follows"): POST /contribute/road-ways answers the OSM ways under the line and the name, road type and surface of
 * the longest, in the form's own values. The line from the owner's report: the Oosterblokker, drawn to the Zittend
 * crossing, gave a form with no name (2026-10-27).
 */
final class DrawnRoadFactsTest extends WebTestCase
{
    private const string LINE = '{"line":[[5.1374581,52.6773247],[5.135,52.6765],[5.132,52.675]]}';

    private function client(): KernelBrowser
    {
        $client = static::createClient();
        static::getContainer()->set(RoadWayLocator::class, new class implements RoadWayLocator {
            public function waysAlong(array $line): array
            {
                return ['way/581720829', 'way/6576390'];
            }

            public function roadsAlong(array $line): array
            {
                return [
                    ['ref' => 'way/581720829', 'metres' => 285, 'name' => 'Oosterblokker', 'highway' => 'tertiary'],
                    ['ref' => 'way/6576390', 'metres' => 173, 'name' => 'Binnenwijzend', 'highway' => 'tertiary'],
                ];
            }
        });

        return $client;
    }

    private function signIn(KernelBrowser $client): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('road-ways-'.uniqid('', true).'@test.test');
        $user->setPassword('x');
        $em->persist($user);
        $em->flush();
        $client->loginUser($user);
    }

    private function post(KernelBrowser $client, string $body, bool $token = true): void
    {
        $client->request('POST', '/contribute/road-ways', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
        ] + ($token ? ['HTTP_X_CC_TOKEN' => static::getContainer()->get(CsrfTokenManagerInterface::class)->getToken('route-snap')->getValue()] : []), $body);
    }

    public function testTheLongestRoadNamesTheForm(): void
    {
        $client = $this->client();
        $this->signIn($client);
        $this->post($client, self::LINE);

        self::assertResponseIsSuccessful();
        $d = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('Oosterblokker', $d['name']);
        self::assertSame('Local road', $d['roadType']);
        self::assertSame([
            ['ref' => 'way/581720829', 'name' => 'Oosterblokker', 'metres' => 285],
            ['ref' => 'way/6576390', 'name' => 'Binnenwijzend', 'metres' => 173],
        ], $d['ways']);
    }

    /** The surface is the class our road pieces hold for the longest way, never a guess. */
    public function testTheSurfaceIsWhatOurRoadPiecesHold(): void
    {
        $client = $this->client();
        $this->signIn($client);
        $db = static::getContainer()->get(Connection::class);
        $db->executeStatement('CREATE TABLE public.coverage_way_surface (way_id bigint PRIMARY KEY, surface text)');
        $db->executeStatement("INSERT INTO coverage_way_surface VALUES (581720829, 'paved')");
        try {
            $this->post($client, self::LINE);
            self::assertSame('Asphalt', json_decode((string) $client->getResponse()->getContent(), true)['surface']);
        } finally {
            $db->executeStatement('DROP TABLE IF EXISTS public.coverage_way_surface');
        }
    }

    public function testWithoutOurRoadPiecesNoSurfaceIsFilled(): void
    {
        $client = $this->client();
        $this->signIn($client);
        $this->post($client, self::LINE);

        self::assertNull(json_decode((string) $client->getResponse()->getContent(), true)['surface']);
    }

    public function testAnonymousGetsClean401(): void
    {
        $client = $this->client();
        $this->post($client, self::LINE);

        self::assertResponseStatusCodeSame(401);
    }

    public function testNoTokenIsRefused(): void
    {
        $client = $this->client();
        $this->signIn($client);
        $this->post($client, self::LINE, token: false);

        self::assertResponseStatusCodeSame(403);
    }

    public function testABadLineIsRefused(): void
    {
        $client = $this->client();
        $this->signIn($client);
        $this->post($client, '{"line":[[5.13,52.67]]}');
        self::assertResponseStatusCodeSame(400);
        $this->post($client, '{"line":[[5.13,52.67],[500,52.67]]}');
        self::assertResponseStatusCodeSame(400);
    }
}
