<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The /account/contributions dashboard renders REAL rows only: no leftover preview/sample
 * data, the user's own route ballots, and their curator applications with a
 * door to apply when there are none.
 */
final class ProfileDashboardTest extends WebTestCase
{
    private function makeUser(string $email): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $u = (new User())->setEmail($email)->setDisplayName('Dash');
        $u->setEmailVerified(true)->setEmailVerifiedAt(new \DateTimeImmutable())->setRoles([]);
        $u->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($u, 'password1234'));
        $em->persist($u);
        $em->flush();

        return $u;
    }

    public function testNoSampleDataAndHonestEmptyStates(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeUser('dash-empty@test.test'));

        $client->request('GET', '/account/contributions');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        // The old preview panes hardcoded these for every account.
        self::assertStringNotContainsString('Dolomites', $html);
        self::assertStringNotContainsString('Peak District', $html);
        self::assertStringNotContainsString('47 days', $html);

        // Honest empty states + the apply door instead. "Curator applications"
        // is the heading only once there IS one; a rider who has never applied
        // gets an invitation written for them, not a curator's empty list.
        self::assertStringNotContainsString('Curator applications', $html);
        self::assertStringContainsString('Curating', $html);
        self::assertStringContainsString('See where curators are needed', $html);
        self::assertStringContainsString('No votes yet', $html);
        // Empty panes render the centred block, not a bare line.
        self::assertStringContainsString('empty-state', $html);
    }

    public function testCuratorApplicationAndVoteRowsRender(): void
    {
        $client = static::createClient();
        $user = $this->makeUser('dash-rows@test.test');
        $db = static::getContainer()->get(Connection::class);

        $db->executeStatement(
            "INSERT INTO curator_application (user_id, country_code, about, status, created_at)
             VALUES (?, 'BE', 'I ride here weekly.', 'pending', NOW())",
            [(int) $user->getId()],
        );
        $db->executeStatement(
            "INSERT INTO recommended_route (name, geom, state, source, source_ref, attributes, created_at, updated_at)
             VALUES ('Dash Loop', ST_SetSRID(ST_GeomFromText('LINESTRING(4.5 50.5, 4.6 50.6)'), 4326), 'unverified', 'seed', 'dash-loop-t', '{}', NOW(), NOW())",
        );
        $routeId = (int) $db->fetchOne("SELECT id FROM recommended_route WHERE source_ref = 'dash-loop-t'");
        $db->executeStatement(
            "INSERT INTO route_vote (route_id, user_id, season, bike_type, created_at) VALUES (?, ?, 'summer', 'road', NOW())",
            [$routeId, (int) $user->getId()],
        );
        $db->executeStatement(
            "INSERT INTO item (letter, name, geom, country_code, state, source, source_ref, attributes, created_at, updated_at)
             VALUES ('B', 'Dash Fountain', ST_SetSRID(ST_GeomFromText('POINT(4.5 50.5)'), 4326), 'BE', 'verified', 'seed', 'dash-fountain-t', '{}', NOW(), NOW())",
        );
        $itemId = (int) $db->fetchOne("SELECT id FROM item WHERE source_ref = 'dash-fountain-t' AND letter = 'B'");
        $db->executeStatement(
            "INSERT INTO item_confirmation (item_id, user_id, stance, created_at, updated_at) VALUES (?, ?, 'potable', NOW(), NOW())",
            [$itemId, (int) $user->getId()],
        );

        $client->loginUser($user);
        $client->request('GET', '/account/contributions');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('Pending review', $html, 'application status pill');
        self::assertStringContainsString('Whole country', $html, 'country-wide application label');
        self::assertStringContainsString('Dash Loop', $html, 'own route ballot listed');
        self::assertStringContainsString('Dash Fountain', $html, 'own place confirmation listed');
        self::assertStringContainsString('Potable', $html, 'confirmation stance pill');
        self::assertStringNotContainsString('See where curators are needed', $html, 'door hidden once applied');
    }

    /**
     * A rider's proposed routes are contributions with their own Routes chip:
     * first after All, showing only the routes, while another kind's chip
     * hides them.
     */
    public function testRoutesChipFiltersToProposedRoutes(): void
    {
        $client = static::createClient();
        $user = $this->makeUser('dash-routes@test.test');
        $uid = (int) $user->getId();
        $db = static::getContainer()->get(Connection::class);

        $db->executeStatement(
            "INSERT INTO recommended_route (name, geom, state, source, source_ref, attributes, proposed_by, created_at, updated_at)
             VALUES ('Chip Loop', ST_SetSRID(ST_GeomFromText('LINESTRING(4.5 50.5, 4.6 50.6)'), 4326), 'submitted', 'user', 'chip-loop-t', '{}', ?, NOW(), NOW())",
            [$uid],
        );
        $routeId = (int) $db->fetchOne("SELECT id FROM recommended_route WHERE source_ref = 'chip-loop-t'");
        $db->executeStatement(
            "INSERT INTO submission (type, letter, user_id, status, title, geom, country_code, changes, payload, created_at)
             VALUES ('new', 'N', ?, 'pending', 'Chip Climb', ST_SetSRID(ST_MakePoint(4.5, 50.5), 4326), 'BE', '{}', '{}', NOW())",
            [$uid],
        );

        $client->loginUser($user);
        $crawler = $client->request('GET', '/account/contributions');
        self::assertResponseIsSuccessful();
        $chips = $crawler->filter('#p-contrib .lfilter a.lchip:not(.lchip-status)');
        self::assertSame(['All', 'Routes', 'Climbs'], $chips->each(static fn ($c): string => trim($c->text())));
        self::assertSame('/account/contributions?letter=R', $chips->eq(1)->attr('href'));
        self::assertSame(1, $crawler->filter('#route-'.$routeId)->count(), 'All lists the route');
        self::assertStringContainsString('Chip Climb', $crawler->filter('#p-contrib')->text());

        $crawler = $client->request('GET', '/account/contributions?letter=R');
        self::assertResponseIsSuccessful();
        self::assertMatchesRegularExpression('/\bon\b/', (string) $crawler->filter('#p-contrib .lfilter a.lchip')->eq(1)->attr('class'), 'Routes chip lit');
        self::assertSame(1, $crawler->filter('#route-'.$routeId)->count(), 'Routes lists the route');
        self::assertStringNotContainsString('Chip Climb', $crawler->filter('#p-contrib')->text(), 'Routes hides other kinds');
        self::assertSame(0, $crawler->filter('#p-contrib .empty-state')->count(), 'no empty state over a route list');

        $crawler = $client->request('GET', '/account/contributions?letter=N');
        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('#route-'.$routeId)->count(), 'Climbs hides the route');
        self::assertStringContainsString('Chip Climb', $crawler->filter('#p-contrib')->text());
    }

    /**
     * Places and routes are one list, newest first, under one pager: a route
     * proposed after a full page of place submissions is the first card on
     * page one, not a card below the places' pager (owner 2026-09-30).
     */
    public function testRouteNewerThanEverySubmissionLeadsTheMergedList(): void
    {
        $client = static::createClient();
        $user = $this->makeUser('dash-merged@test.test');
        $uid = (int) $user->getId();
        $db = static::getContainer()->get(Connection::class);

        for ($i = 1; $i <= 21; ++$i) {
            $db->executeStatement(
                "INSERT INTO submission (type, letter, user_id, status, title, geom, country_code, changes, payload, created_at)
                 VALUES ('new', 'B', ?, 'pending', ?, ST_SetSRID(ST_MakePoint(4.5, 50.5), 4326), 'BE', '{}', '{}', NOW() - make_interval(days => ?))",
                [$uid, sprintf('Merged Fountain %02d', $i), 30 - $i],
            );
        }
        $db->executeStatement(
            "INSERT INTO recommended_route (name, geom, state, source, source_ref, attributes, proposed_by, created_at, updated_at)
             VALUES ('Merged Loop', ST_SetSRID(ST_GeomFromText('LINESTRING(4.5 50.5, 4.6 50.6)'), 4326), 'submitted', 'user', 'merged-loop-t', '{}', ?, NOW(), NOW())",
            [$uid],
        );
        $routeId = (int) $db->fetchOne("SELECT id FROM recommended_route WHERE source_ref = 'merged-loop-t'");

        $client->loginUser($user);
        $crawler = $client->request('GET', '/account/contributions');
        self::assertResponseIsSuccessful();
        $cards = $crawler->filter('#qList > .q-item');
        self::assertCount(20, $cards, 'one page of twenty cards');
        self::assertSame('route-'.$routeId, $cards->eq(0)->attr('id'), 'the newest contribution, the route, comes first');
        self::assertStringContainsString('Merged Fountain 21', $cards->eq(1)->text());
        self::assertSame(1, $crawler->filter('#p-contrib nav.pager')->count(), 'one pager for the whole list');
        self::assertStringNotContainsString('rpage', (string) $client->getResponse()->getContent());
        self::assertSame(1, $cards->eq(0)->filter('.q-tag--route')->count(), 'the route card carries the Route tag');

        $crawler = $client->request('GET', '/account/contributions?page=2');
        self::assertResponseIsSuccessful();
        $cards = $crawler->filter('#qList > .q-item');
        self::assertCount(2, $cards, 'page two holds the two oldest places');
        self::assertStringContainsString('Merged Fountain 01', $cards->eq(1)->text());
        self::assertSame(0, $crawler->filter('#route-'.$routeId)->count(), 'the route is on page one only');

        // Routes: the rider's route proposals alone, the same card, one pager.
        $crawler = $client->request('GET', '/account/contributions?letter=R');
        self::assertResponseIsSuccessful();
        $cards = $crawler->filter('#qList > .q-item');
        self::assertCount(1, $cards);
        self::assertSame('route-'.$routeId, $cards->eq(0)->attr('id'));

        // The withdrawn view never lists a route: a proposal has no withdrawn state.
        $crawler = $client->request('GET', '/account/contributions?status=withdrawn');
        self::assertSame(0, $crawler->filter('#route-'.$routeId)->count());
    }

    /**
     * A route card offers what a place card does while the proposal waits:
     * Map (the proposer's own preview of the submitted route) and Edit. Once
     * a curator has decided it, Edit is gone.
     */
    public function testRouteCardOffersMapAndEditWhileSubmitted(): void
    {
        $client = static::createClient();
        $user = $this->makeUser('dash-route-acts@test.test');
        $db = static::getContainer()->get(Connection::class);
        $db->executeStatement(
            "INSERT INTO recommended_route (name, geom, state, source, source_ref, attributes, proposed_by, created_at, updated_at)
             VALUES ('Acts Loop', ST_SetSRID(ST_GeomFromText('LINESTRING(4.5 50.5, 4.6 50.6)'), 4326), 'submitted', 'user', 'acts-loop-t', '{}', ?, NOW(), NOW())",
            [(int) $user->getId()],
        );
        $routeId = (int) $db->fetchOne("SELECT id FROM recommended_route WHERE source_ref = 'acts-loop-t'");

        $client->loginUser($user);
        $crawler = $client->request('GET', '/account/contributions');
        $card = $crawler->filter('#route-'.$routeId);
        self::assertSame('/map?route='.$routeId, $card->filter('a.q-link')->eq(0)->attr('href'), 'Map opens the route');
        self::assertSame('/propose-route/'.$routeId.'/edit', $card->filter('a.q-link')->eq(1)->attr('href'), 'Edit opens the proposal form');

        // The map carries the waiting route for its proposer.
        $client->request('GET', '/map?route='.$routeId);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Acts Loop', (string) $client->getResponse()->getContent(), 'proposer sees the submitted route');

        $db->executeStatement("UPDATE recommended_route SET state = 'unverified' WHERE id = ?", [$routeId]);
        $crawler = $client->request('GET', '/account/contributions');
        $card = $crawler->filter('#route-'.$routeId);
        self::assertSame(1, $card->filter('a.q-link')->count(), 'after the decision only Map remains');
        self::assertSame(0, $card->filter('a[href$="/edit"]')->count());
    }
}
