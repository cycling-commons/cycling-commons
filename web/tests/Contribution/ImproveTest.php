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
 * Improve page: auth-gate, rendering, and CSRF-protected POST to the contribution stub.
 *
 * All tests use a plain ROLE_USER to avoid triggering TwoFactorSetupEnforcer
 * (which redirects elevated roles without a TOTP secret to /2fa/setup).
 *
 * Test isolation: DAMA\DoctrineTestBundle wraps each test in a rolled-back transaction.
 */
final class ImproveTest extends WebTestCase
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
        $user->setDisplayName('Test Contributor');
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

    public function testAnonGetImproveRedirectsToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/improve');

        self::assertResponseRedirects('/login', 302);
    }

    // ── Authenticated GET ────────────────────────────────────────────────────

    public function testAuthenticatedUserCanGetImprovePage(): void
    {
        $client = static::createClient();

        $email = 'improve-get@example.com';
        $plain = 'securepass12345!';
        $this->createUser($email, $plain);
        $this->loginAs($client, $email, $plain);

        $client->request('GET', '/improve');

        self::assertResponseIsSuccessful();
        // Wizard heading present
        self::assertSelectorTextContains('h1.disp', 'Improve or add a place');
        // Step 1 is active
        self::assertSelectorExists('.stepper li.on');
        // whatChanged field present in DOM
        self::assertSelectorExists('[name="improve[whatChanged]"]');
    }

    // ── Authenticated GET with query params (deep-link) ──────────────────────

    public function testDeepLinkWithItemAndModeReturns200(): void
    {
        $client = static::createClient();

        $email = 'improve-deep@example.com';
        $plain = 'securepass12345!';
        $this->createUser($email, $plain);
        $this->loginAs($client, $email, $plain);

        $client->request('GET', '/improve?item=water-fountain&mode=add');

        self::assertResponseIsSuccessful();
    }

    // ── Authenticated POST — valid submission ────────────────────────────────

    public function testValidPostShowsHonestStubReceipt(): void
    {
        $client = static::createClient();

        $email = 'improve-post@example.com';
        $plain = 'securepass12345!';
        $this->createUser($email, $plain);
        $this->loginAs($client, $email, $plain);

        $crawler = $client->request('GET', '/improve');
        self::assertResponseIsSuccessful();

        // Select the "Next →" button to get the right form (not nav logout form).
        $form = $crawler->selectButton('Next →')->form([
            'improve[whatChanged]' => 'The water fountain is now operational. It has a dog bowl too.',
            'improve[note]' => 'Open year-round, tested July 2026.',
            'improve[lat]' => '50.499',
            'improve[lng]' => '5.739',
            'improve[place]' => 'Remouchamps, Wallonia',
        ]);
        $client->submit($form);

        self::assertResponseIsSuccessful();
        // Honest stub state: queued for review / not yet persisted
        self::assertSelectorTextContains('.receipt h2', 'Suggestion submitted.');
        self::assertSelectorTextContains('.receipt .stub-note', 'not yet persisted');
        // Reference is present (CC- prefix)
        self::assertSelectorTextContains('.receipt .ref', 'CC-');
    }
}
