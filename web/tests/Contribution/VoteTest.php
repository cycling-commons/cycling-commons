<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Contribution;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Vote page: auth-gate, rendering, and CSRF-protected POST to the contribution stub.
 *
 * All tests use a plain ROLE_USER to avoid triggering TwoFactorSetupEnforcer
 * (which redirects elevated roles without a TOTP secret to /2fa/setup).
 *
 * Test isolation: DAMA\DoctrineTestBundle wraps each test in a rolled-back transaction.
 */
final class VoteTest extends WebTestCase
{
    // ── Helpers ─────────────────────────────────────────────────────────────

    private function createUser(string $email, string $plain): void
    {
        $container = static::getContainer();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Test Voter');
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles([]);
        $user->setPassword($hasher->hashPassword($user, $plain));

        $em->persist($user);
        $em->flush();
    }

    private function loginAs(KernelBrowser $client, string $email, string $plain): void
    {
        $crawler = $client->request('GET', '/login');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Sign in')->form([
            '_username' => $email,
            '_password' => $plain,
        ]);
        $client->submit($form);

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    // ── Auth-gate ────────────────────────────────────────────────────────────

    public function testAnonGetVoteRedirectsToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/vote');

        self::assertResponseRedirects('/login', 302);
    }

    // ── Authenticated GET ────────────────────────────────────────────────────

    public function testAuthenticatedUserCanGetVotePage(): void
    {
        $client = static::createClient();

        $email = 'vote-get@example.com';
        $plain = 'securepass12345!';
        $this->createUser($email, $plain);
        $this->loginAs($client, $email, $plain);

        $client->request('GET', '/vote');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('button[type="submit"]');
        // Ballot panel heading
        self::assertSelectorExists('.ballot');
    }

    // ── Authenticated POST — valid ballot ────────────────────────────────────

    public function testValidPostShowsHonestStubReceipt(): void
    {
        $client = static::createClient();

        $email = 'vote-post@example.com';
        $plain = 'securepass12345!';
        $this->createUser($email, $plain);
        $this->loginAs($client, $email, $plain);

        $crawler = $client->request('GET', '/vote');
        self::assertResponseIsSuccessful();

        // Select the submit button to get the correct form (not the nav logout form)
        $form = $crawler->selectButton('Submit ballot')->form([
            'vote[ballot]' => 'climbs:Côte de la Redoute|climbs:Mur de Huy',
        ]);
        $client->submit($form);

        self::assertResponseIsSuccessful();
        // Honest stub state: ballot recorded / queued for review
        self::assertSelectorTextContains('.receipt h2', 'Ballot recorded.');
        self::assertSelectorTextContains('.receipt .stub-note', 'not yet persisted');
        // Reference is present (CC- prefix)
        self::assertSelectorTextContains('.receipt .ref', 'CC-');
    }

    // ── Authenticated POST — empty ballot (rejected) ─────────────────────────

    public function testEmptyBallotIsRejected(): void
    {
        $client = static::createClient();

        $email = 'vote-empty@example.com';
        $plain = 'securepass12345!';
        $this->createUser($email, $plain);
        $this->loginAs($client, $email, $plain);

        $crawler = $client->request('GET', '/vote');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Submit ballot')->form([
            'vote[ballot]' => '',
        ]);
        $client->submit($form);

        // Form validation fails → Symfony returns 422 Unprocessable Entity
        self::assertResponseStatusCodeSame(422);
    }
}
