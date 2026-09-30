<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

/**
 * Where a new rider lands: the whole way through, from the sign-up form and
 * the confirmation mail to the sign-in form. The first sign-in after
 * confirming goes to the profile (settings), every later one to the dashboard
 * (account-and-auth.md §8).
 *
 * Test isolation: DAMA\DoctrineTestBundle wraps each test in a rolled-back
 * transaction.
 */
final class FirstSignInLandingTest extends WebTestCase
{
    use GuardedSignupTrait;
    use MailerAssertionsTrait;

    private const string PASSWORD = 'securepass12345!';

    private function client(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    /** The signed link from the one confirmation mail, relative for the test client. */
    private function confirmationLink(): string
    {
        self::assertEmailCount(1);
        $email = $this->getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame(1, preg_match('/href=["\']([^"\']*\/verify\/email[^"\']*)["\']/', (string) $email->getHtmlBody(), $m));
        $parsed = parse_url(html_entity_decode($m[1]));

        return ($parsed['path'] ?? '').'?'.($parsed['query'] ?? '');
    }

    /** Signs in on the page the client is sent to, and returns where the answer points. */
    private function signIn(KernelBrowser $client, string $email, string $loginPath = '/login'): string
    {
        $crawler = $client->request('GET', $loginPath);
        self::assertResponseIsSuccessful();
        $client->submit($crawler->filter('form.cc-validate')->form([
            '_username' => $email,
            '_password' => self::PASSWORD,
        ]));
        self::assertResponseRedirects();

        return (string) $client->getResponse()->headers->get('Location');
    }

    private function confirm(KernelBrowser $client, string $link): string
    {
        $client->request('GET', $link);
        self::assertResponseRedirects('/login');

        return (string) $client->getResponse()->headers->get('Location');
    }

    private function user(string $email): User
    {
        $user = static::getContainer()->get(UserRepository::class)->findByEmail($email);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    public function testTheFirstSignInAfterConfirmingLandsOnContributionsAndTheNextOnTheDashboard(): void
    {
        $client = $this->client();
        $this->signUp($client, 'landing@example.com');
        self::assertResponseIsSuccessful();

        $loginPath = $this->confirm($client, $this->confirmationLink());

        self::assertSame('/account/settings', $this->signIn($client, 'landing@example.com', $loginPath));
        $client->followRedirect();
        self::assertResponseIsSuccessful();

        $client->restart();
        self::assertSame('/account', $this->signIn($client, 'landing@example.com'));
    }

    /**
     * The dormancy clock can hold a time from before the confirmation: the
     * migration that added it started it at `created_at`, and older accounts
     * could sign in unconfirmed. That time is not a sign-in since confirming.
     */
    public function testAClockSetBeforeConfirmingStillGivesTheFirstLanding(): void
    {
        $client = $this->client();
        $this->signUp($client, 'backfilled@example.com');
        $link = $this->confirmationLink();

        $user = $this->user('backfilled@example.com');
        $createdAt = $user->getCreatedAt();
        self::assertNotNull($createdAt);
        $user->recordLogin($createdAt->modify('-1 second'));
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $loginPath = $this->confirm($client, $link);

        self::assertSame('/account/settings', $this->signIn($client, 'backfilled@example.com', $loginPath));

        $client->restart();
        self::assertSame('/account', $this->signIn($client, 'backfilled@example.com'));
    }

    /** A page the rider asked for still wins over either landing. */
    public function testThePageThatAskedForTheSignInStillWins(): void
    {
        $client = $this->client();
        $this->signUp($client, 'asked@example.com');
        $this->confirm($client, $this->confirmationLink());

        self::assertSame('http://localhost/account/settings', $this->signIn($client, 'asked@example.com', '/login?_target_path=%2Faccount%2Fsettings'));
    }
}
