<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Tests\Planner\RideFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * The account's Rides page (docs/specs/route-planner.md section 6.2): the
 * rides a rider planned and saved, theirs alone, with a way onto the map
 * and a way to delete one.
 */
final class AccountRidesControllerTest extends WebTestCase
{
    use RideFixtures;

    private static function user(string $email): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail($email);
        $user->setPassword('x');
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private static function db(): Connection
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    public function testARiderSeesTheirOwnRidesAndNeverAnotherRiders(): void
    {
        $client = static::createClient();
        $a = self::user('rides-a@test.test');
        $b = self::user('rides-b@test.test');
        self::seedRide(self::db(), (int) $a->getId(), 'Spa loop of A');
        self::seedRide(self::db(), (int) $b->getId(), 'Secret ride of B');
        $client->loginUser($a);

        $client->request('GET', '/account/rides');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Spa loop of A', $html);
        self::assertStringNotContainsString('Secret ride of B', $html);
        self::assertStringContainsString('/map?ride=', $html);
    }

    public function testAVisitorIsSentToLogIn(): void
    {
        $client = static::createClient();
        $client->request('GET', '/account/rides');
        self::assertResponseRedirects();
    }

    public function testAnEmptyListPointsToThePlanner(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('rides-empty@test.test'));
        $client->request('GET', '/account/rides');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/map?panel=plan', (string) $client->getResponse()->getContent());
    }

    public function testDeletingOwnRideRemovesItAndAnotherRidersIs404(): void
    {
        $client = static::createClient();
        $a = self::user('rides-del-a@test.test');
        $b = self::user('rides-del-b@test.test');
        $mine = self::seedRide(self::db(), (int) $a->getId(), 'Mine');
        $theirs = self::seedRide(self::db(), (int) $b->getId(), 'Theirs');
        $client->loginUser($a);
        $token = static::getContainer()->get(CsrfTokenManagerInterface::class)->getToken('plan-delete')->getValue();

        $client->request('POST', '/account/rides/'.$theirs.'/delete', ['_token' => $token], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', '/account/rides/'.$mine.'/delete', ['_token' => $token], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertResponseRedirects('/account/rides');
        self::assertSame(0, (int) self::db()->fetchOne('SELECT COUNT(*) FROM planned_ride WHERE id = :id', ['id' => $mine]));
        self::assertSame(1, (int) self::db()->fetchOne('SELECT COUNT(*) FROM planned_ride WHERE id = :id', ['id' => $theirs]));
    }

    public function testDeleteWithoutATokenIs403(): void
    {
        $client = static::createClient();
        $a = self::user('rides-del-tok@test.test');
        $mine = self::seedRide(self::db(), (int) $a->getId(), 'Mine');
        $client->loginUser($a);
        $client->request('POST', '/account/rides/'.$mine.'/delete', ['_token' => 'nope'], [], ['HTTP_SEC_FETCH_SITE' => 'cross-site']);
        self::assertResponseStatusCodeSame(403);
    }
}
