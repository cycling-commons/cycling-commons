<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Tests\Auth;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Case-insensitive display-name uniqueness (spec 2026-07-14), enforced by the
 * entity-level UniqueEntity on displayNameCanonical — covered here on both
 * user-facing write paths (registration + settings).
 *
 * Test isolation: DAMA\DoctrineTestBundle wraps each test in a rolled-back
 * transaction.
 */
final class DisplayNameUniquenessTest extends WebTestCase
{
    // ── Helpers (same pattern as ProfileSettingsTest) ───────────────────────

    private function createUser(string $email, string $plain, string $displayName): string
    {
        $container = static::getContainer();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName($displayName);
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles([]);
        $user->setPassword($hasher->hashPassword($user, $plain));

        $em->persist($user);
        $em->flush();

        return $plain;
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

    private function fetchUser(string $email): User
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        /** @var UserRepository $repo */
        $repo = static::getContainer()->get(UserRepository::class);
        $user = $repo->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    // ── Registration path ────────────────────────────────────────────────────

    public function testRegistrationRejectsCaseVariantDuplicateDisplayName(): void
    {
        $client = static::createClient();
        $this->createUser('original@example.com', 'securepass12345!', 'Hanne V');

        $crawler = $client->request('GET', '/register');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Create account')->form([
            'registration_form[email]' => 'copycat@example.com',
            'registration_form[displayName]' => 'HANNE v',
            'registration_form[plainPassword][first]' => 'securepass12345!',
            'registration_form[plainPassword][second]' => 'securepass12345!',
            'registration_form[agreeTerms]' => true,
        ]);
        $client->submit($form);

        // Form validation failure — no second account is created.
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'This display name is already taken');

        /** @var UserRepository $repo */
        $repo = static::getContainer()->get(UserRepository::class);
        self::assertNull($repo->findOneBy(['email' => 'copycat@example.com']));
    }

    public function testRegistrationAcceptsUniqueDisplayName(): void
    {
        $client = static::createClient();
        $this->createUser('taken@example.com', 'securepass12345!', 'Taken Name');

        $crawler = $client->request('GET', '/register');
        $form = $crawler->selectButton('Create account')->form([
            'registration_form[email]' => 'fresh@example.com',
            'registration_form[displayName]' => 'Fresh Name',
            'registration_form[plainPassword][first]' => 'securepass12345!',
            'registration_form[plainPassword][second]' => 'securepass12345!',
            'registration_form[agreeTerms]' => true,
        ]);
        $client->submit($form);

        // Check-email page, user created.
        self::assertResponseIsSuccessful();
        /** @var UserRepository $repo */
        $repo = static::getContainer()->get(UserRepository::class);
        self::assertNotNull($repo->findOneBy(['email' => 'fresh@example.com']));
    }

    // ── Settings path ────────────────────────────────────────────────────────

    public function testSettingsRejectsTakingAnotherUsersNameCaseInsensitively(): void
    {
        $client = static::createClient();
        $this->createUser('alpha@example.com', 'securepass12345!', 'Alpha Rider');
        $plain = $this->createUser('beta@example.com', 'securepass12345!', 'Beta Rider');
        $this->loginAs($client, 'beta@example.com', $plain);

        $crawler = $client->request('GET', '/settings');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save profile')->form([
            'settings[displayName]' => 'ALPHA RIDER',
        ]);
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        $user = $this->fetchUser('beta@example.com');
        self::assertSame('Beta Rider', $user->getDisplayName(), 'display name must be unchanged');
    }

    public function testSettingsAllowsKeepingYourOwnName(): void
    {
        // UniqueEntity must not flag a user's own row as the duplicate.
        $client = static::createClient();
        $plain = $this->createUser('self@example.com', 'securepass12345!', 'Self Rider');
        $this->loginAs($client, 'self@example.com', $plain);

        $crawler = $client->request('GET', '/settings');
        $form = $crawler->selectButton('Save profile')->form([
            'settings[displayName]' => 'Self Rider',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/settings');
    }

    public function testSettingsAllowsCaseOnlyRenameOfYourOwnName(): void
    {
        // Recasing your OWN name keeps the same canonical value — the unique
        // check must treat that as "same row", not a collision.
        $client = static::createClient();
        $plain = $this->createUser('recase@example.com', 'securepass12345!', 'recase rider');
        $this->loginAs($client, 'recase@example.com', $plain);

        $crawler = $client->request('GET', '/settings');
        $form = $crawler->selectButton('Save profile')->form([
            'settings[displayName]' => 'Recase Rider',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/settings');
        $user = $this->fetchUser('recase@example.com');
        self::assertSame('Recase Rider', $user->getDisplayName());
    }
}
