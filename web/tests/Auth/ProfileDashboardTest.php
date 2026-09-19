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
}
