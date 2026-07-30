<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The /profile dashboard renders REAL rows only: no leftover preview/sample
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

        $client->request('GET', '/profile');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        // The old preview panes hardcoded these for every account.
        self::assertStringNotContainsString('Dolomites', $html);
        self::assertStringNotContainsString('Peak District', $html);
        self::assertStringNotContainsString('47 days', $html);

        // Honest empty states + the apply door instead.
        self::assertStringContainsString('Curator applications', $html);
        self::assertStringContainsString('See where curators are needed', $html);
        self::assertStringContainsString('No route votes yet', $html);
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

        $client->loginUser($user);
        $client->request('GET', '/profile');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('Pending review', $html, 'application status pill');
        self::assertStringContainsString('Whole country', $html, 'country-wide application label');
        self::assertStringContainsString('Dash Loop', $html, 'own route ballot listed');
        self::assertStringNotContainsString('See where curators are needed', $html, 'door hidden once applied');
    }
}
