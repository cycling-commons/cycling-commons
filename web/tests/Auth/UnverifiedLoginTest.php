<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * No sign-in before the address is confirmed (account-and-auth.md §2).
 *
 * The check runs AFTER the password: a wrong password on an unconfirmed
 * account gets the ordinary error, so the login form does not tell a stranger
 * which addresses are waiting for confirmation.
 */
final class UnverifiedLoginTest extends WebTestCase
{
    private const string PASSWORD = 'securepass12345!';

    private function rider(string $email, bool $verified): void
    {
        $container = static::getContainer();
        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Waiting Rider');
        $user->setEmailVerified($verified);
        $user->setRoles([]);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));

        $em = $container->get(EntityManagerInterface::class);
        $em->persist($user);
        $em->flush();
    }

    private function login(KernelBrowser $client, string $email, string $password): void
    {
        $page = $client->request('GET', '/login');
        $client->submit($page->selectButton('Sign in')->form(['_username' => $email, '_password' => $password]));
    }

    public function testAnUnconfirmedAccountCannotSignIn(): void
    {
        $client = static::createClient();
        $this->rider('waiting@example.com', verified: false);

        $this->login($client, 'waiting@example.com', self::PASSWORD);

        self::assertResponseRedirects('/login');
        $client->followRedirect();
        self::assertSelectorTextContains('.alert-error', 'Confirm your email address');
        self::assertSelectorExists('.alert-error a[href="/verify/resend"]');
        self::assertNull(static::getContainer()->get('security.token_storage')->getToken());
    }

    /** The right password is not a guess: it must not walk the account into the lockout. */
    public function testTheRightPasswordOnAnUnconfirmedAccountIsNotAFailedAttempt(): void
    {
        $client = static::createClient();
        $this->rider('patient@example.com', verified: false);

        $this->login($client, 'patient@example.com', self::PASSWORD);

        $user = static::getContainer()->get(UserRepository::class)->findByEmail('patient@example.com');
        self::assertNotNull($user);
        self::assertSame(0, $user->getFailedLoginAttempts());
    }

    public function testAWrongPasswordOnAnUnconfirmedAccountSaysNothingMore(): void
    {
        $client = static::createClient();
        $this->rider('waiting2@example.com', verified: false);

        $this->login($client, 'waiting2@example.com', 'not-the-password');

        $client->followRedirect();
        self::assertSelectorNotExists('.alert-error a[href="/verify/resend"]');
        self::assertSelectorTextNotContains('.alert-error', 'Confirm your email address');
    }

    public function testAConfirmedAccountSignsInWithAnyCaseOfItsAddress(): void
    {
        $client = static::createClient();
        $this->rider('confirmed@example.com', verified: true);

        $this->login($client, 'Confirmed@Example.COM', self::PASSWORD);

        self::assertResponseRedirects();
        self::assertStringNotContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }
}
