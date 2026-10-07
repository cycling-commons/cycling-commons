<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Account;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * The browser reports its time zone while the rider's choice is automatic
 * (docs/specs/account-and-auth.md §9). Only a real zone is stored.
 */
final class TimeZoneControllerTest extends WebTestCase
{
    private function rider(KernelBrowser $client): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $u = (new User())->setEmail('tz-'.bin2hex(random_bytes(3)).'@example.com');
        $u->setPassword('x');
        $u->setDisplayName('Rider');
        $u->setEmailVerified(true);
        $em->persist($u);
        $em->flush();
        $client->loginUser($u);

        return $u;
    }

    private function post(KernelBrowser $client, string $zone, ?string $token = null): void
    {
        $token ??= static::getContainer()->get(CsrfTokenManagerInterface::class)->getToken('time-zone')->getValue();
        $client->request('POST', '/account/time-zone', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CC_TOKEN' => $token,
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
        ], json_encode(['zone' => $zone], \JSON_THROW_ON_ERROR));
    }

    private function stored(User $u): ?string
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->find(User::class, $u->getId())?->getDetectedTimeZone();
    }

    public function testTheBrowsersZoneIsStored(): void
    {
        $client = static::createClient();
        $u = $this->rider($client);

        $this->post($client, 'Europe/Amsterdam');

        self::assertResponseStatusCodeSame(204);
        self::assertSame('Europe/Amsterdam', $this->stored($u));
    }

    public function testAZoneThatDoesNotExistIsRefused(): void
    {
        $client = static::createClient();
        $u = $this->rider($client);

        $this->post($client, 'Mars/Olympus');

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->stored($u));
    }

    public function testWithoutTheTokenNothingIsStored(): void
    {
        $client = static::createClient();
        $u = $this->rider($client);

        $this->post($client, 'Europe/Amsterdam', 'wrong');

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->stored($u));
    }

    public function testAVisitorWhoIsNotSignedInCannotReportOne(): void
    {
        $client = static::createClient();
        $this->post($client, 'Europe/Amsterdam');

        self::assertNotSame(204, $client->getResponse()->getStatusCode());
    }
}
