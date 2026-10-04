<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * framework.trusted_hosts (security-architecture.md section 8): a request that
 * names a host outside TRUSTED_HOSTS never reaches a controller, so no mail
 * link can be built from it. .env.test pins the list to the test client's own
 * host, localhost.
 */
final class TrustedHostsTest extends WebTestCase
{
    use MailerAssertionsTrait;

    public function testTheTrustedHostIsServed(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health');

        self::assertResponseIsSuccessful();
    }

    public function testAForeignHostIsRefused(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health', server: ['HTTP_HOST' => 'cyclingcommons.org.evil.example']);

        self::assertResponseStatusCodeSame(400);
    }

    /** The attack the setting exists for: a reset mail whose link names the attacker's host. */
    public function testAResetRequestUnderAForeignHostSendsNoMail(): void
    {
        $client = static::createClient();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User();
        $user->setEmail('host-victim@example.com');
        $user->setDisplayName('Host Victim');
        $user->setPassword('unused');
        $user->setRoles(['ROLE_USER']);
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $em->persist($user);
        $em->flush();

        $client->request('POST', '/reset-password', [
            'reset_password_request_form' => ['email' => 'host-victim@example.com'],
        ], server: ['HTTP_HOST' => 'cyclingcommons.org.evil.example']);

        self::assertResponseStatusCodeSame(400);
        self::assertEmailCount(0);
    }
}
