<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockFileSessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;

/**
 * The login page's "you are already signed in" shortcut, and the one case
 * where it must not fire.
 *
 * Pages that ask for IS_AUTHENTICATED_FULLY - the curator application at
 * /join/{cc} is the one riders actually hit - send a merely-REMEMBERED visitor
 * here to upgrade. Answering that with "you are already signed in" and a
 * redirect to the homepage told them they needed nothing while the page they
 * asked for was still refusing them, and left no way through
 * (owner-reported 2026-08-14, reproduced with a session cookie dropped and the
 * remember-me cookie kept).
 *
 * @see \App\Controller\SecurityController::login
 */
final class LoginPageTest extends WebTestCase
{
    private function rider(string $email): User
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $u = (new User())->setEmail($email)->setDisplayName('Remembered Rider');
        $u->setPassword('x')->setEmailVerified(true)->setRoles([]);
        $em->persist($u);
        $em->flush();

        return $u;
    }

    /**
     * A rider returning on a remember-me cookie: a real user object, but only
     * IS_AUTHENTICATED_REMEMBERED. `loginUser()` cannot express this - it mints
     * a fully-authenticated token - so the token goes into the session by hand,
     * which is exactly what the remember-me listener does on a real request.
     */
    private function loginRemembered(object $client, User $user): void
    {
        $session = new Session(new MockFileSessionStorage());
        $token = new RememberMeToken($user, 'main');
        $session->set('_security_main', serialize($token));
        $session->save();

        $client->getCookieJar()->set(
            new \Symfony\Component\BrowserKit\Cookie($session->getName(), $session->getId()),
        );
    }

    public function testARememberedRiderGetsTheFormRatherThanABounceHome(): void
    {
        $client = static::createClient();
        $this->loginRemembered($client, $this->rider('remembered@example.test'));

        $client->request('GET', '/login');

        self::assertResponseIsSuccessful('the form, because this is exactly who needs to log in again');
        self::assertSelectorExists('input[type="password"]');
    }

    public function testAFullyAuthenticatedRiderIsToldTheyAreAlreadySignedIn(): void
    {
        $client = static::createClient();
        $client->loginUser($this->rider('fully@example.test'));

        $client->request('GET', '/login');

        self::assertResponseRedirects('/');
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'already signed in');
    }

    /**
     * The page they were sent here for. It asks for FULLY, so a remembered
     * rider must be sent to the login form - not silently past it, and not
     * home.
     */
    public function testTheCuratorApplicationSendsARememberedRiderToTheForm(): void
    {
        $client = static::createClient();
        $this->loginRemembered($client, $this->rider('joiner@example.test'));

        $client->request('GET', '/join/NL');
        self::assertResponseRedirects();

        $client->followRedirects();
        $client->request('GET', '/join/NL');
        self::assertSelectorExists('input[type="password"]', 'the login form, reachable rather than a dead end');
    }
}
