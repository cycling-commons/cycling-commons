<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Tests\Auth;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Logout is POST-only and CSRF-protected.
 *
 * Test isolation: DAMA\DoctrineTestBundle wraps each test in a rolled-back transaction.
 */
final class LogoutTest extends WebTestCase
{
    // ── Helpers ─────────────────────────────────────────────────────────────

    private function createVerifiedUser(string $email, string $plain): User
    {
        $container = static::getContainer();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Test Rider');
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles([]);
        $user->setPassword($hasher->hashPassword($user, $plain));

        $em->persist($user);
        $em->flush();

        return $user;
    }

    // ── Tests ────────────────────────────────────────────────────────────────

    /**
     * An authenticated user can log out via the CSRF-protected POST form.
     * After logout, they are redirected to home and their session is cleared.
     */
    public function testAuthenticatedUserCanLogOutViaPostForm(): void
    {
        $client = static::createClient();
        $user = $this->createVerifiedUser('logout-post@example.com', 'hunter2secure!');

        $client->loginUser($user);

        // Confirm we are authenticated by visiting a protected page
        $client->request('GET', '/account/contributions');
        self::assertResponseIsSuccessful();

        // Obtain a valid CSRF token from the container
        /** @var CsrfTokenManagerInterface $csrfManager */
        $csrfManager = static::getContainer()->get(CsrfTokenManagerInterface::class);
        $token = $csrfManager->getToken('logout')->getValue();

        // Submit POST /logout with the CSRF token
        $client->request('POST', '/logout', ['_csrf_token' => $token]);

        // Firewall intercepts and redirects to home (target: home = /)
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertResponseIsSuccessful();

        // After logout, accessing a protected page must redirect to /login
        $client->request('GET', '/account/contributions');
        self::assertResponseRedirects('/login?_target_path=%2Faccount%2Fcontributions', 302);
    }

    /**
     * Signing out marks the way home with `#signed-out`, so the browser
     * forgets the ride being planned there (route-planner.md section 6.0,
     * owner 2026-10-15). A fragment never reaches a server, so the mark
     * tells our side nothing and lands in no log.
     */
    public function testSigningOutMarksTheWayHomeSoTheBrowserForgetsTheRide(): void
    {
        $client = static::createClient();
        $user = $this->createVerifiedUser('logout-mark@example.com', 'hunter2secure!');
        $client->loginUser($user);
        $client->request('GET', '/account/contributions');   // the session the token lives in

        /** @var CsrfTokenManagerInterface $csrfManager */
        $csrfManager = static::getContainer()->get(CsrfTokenManagerInterface::class);
        $client->request('POST', '/logout', ['_csrf_token' => $csrfManager->getToken('logout')->getValue()]);

        self::assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringEndsWith('#signed-out', $location);
        self::assertSame('/', (string) parse_url($location, \PHP_URL_PATH), 'the way home is unchanged, only marked');
        self::assertSame(1, substr_count($location, '#'), 'one fragment, never two');
    }

    /**
     * A GET request to /logout must NOT log the user out (route only accepts POST).
     * The firewall no longer intercepts GET; Symfony returns 405 Method Not Allowed.
     */
    public function testGetLogoutDoesNotLogOut(): void
    {
        $client = static::createClient();
        $user = $this->createVerifiedUser('logout-get@example.com', 'hunter2secure!');

        $client->loginUser($user);

        $client->request('GET', '/logout');

        // Route is POST-only → 405
        self::assertResponseStatusCodeSame(405);

        // Session still intact — protected page still accessible
        $client->request('GET', '/account/contributions');
        self::assertResponseIsSuccessful();
    }
}
