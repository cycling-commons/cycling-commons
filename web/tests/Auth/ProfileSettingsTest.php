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
 * Profile + settings pages: auth-gate, data display, settings persist, password change.
 *
 * All tests use a plain ROLE_USER to avoid triggering TwoFactorSetupEnforcer
 * (which redirects elevated roles without a TOTP secret to /2fa/setup).
 *
 * Test isolation: DAMA\DoctrineTestBundle wraps each test in a rolled-back transaction.
 */
final class ProfileSettingsTest extends WebTestCase
{
    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Create a verified ROLE_USER, return the plain password.
     */
    private function createUser(
        string $email,
        string $plain,
        string $displayName = 'Test Rider',
    ): string {
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
        $user->setRoles([]);     // plain ROLE_USER only (auto-added by getRoles())
        $user->setPassword($hasher->hashPassword($user, $plain));

        $em->persist($user);
        $em->flush();

        return $plain;
    }

    /** Submit the login form and return the client (post-submission, pre-redirect). */
    private function loginAs(KernelBrowser $client, string $email, string $plain): void
    {
        $crawler = $client->request('GET', '/login');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Sign in')->form([
            '_username' => $email,
            '_password' => $plain,
        ]);
        $client->submit($form);

        // Follow login redirect (→ home for plain ROLE_USER)
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    private function fetchUser(string $email): User
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        // Clear the identity map so we read fresh state from the DB.
        $em->clear();

        /** @var UserRepository $repo */
        $repo = static::getContainer()->get(UserRepository::class);
        $user = $repo->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    // ── Anon redirect tests ──────────────────────────────────────────────────

    public function testAnonIsRedirectedFromProfile(): void
    {
        $client = static::createClient();
        $client->request('GET', '/profile');

        self::assertResponseRedirects('/login', 302);
    }

    public function testAnonIsRedirectedFromSettings(): void
    {
        $client = static::createClient();
        $client->request('GET', '/settings');

        self::assertResponseRedirects('/login', 302);
    }

    // ── Authenticated 200 tests ──────────────────────────────────────────────

    public function testAuthenticatedUserCanViewProfile(): void
    {
        $client = static::createClient();

        $email = 'profile-view@example.com';
        $plain = $this->createUser($email, 'securepass12345!', 'Hanne V');
        $this->loginAs($client, $email, $plain);

        $client->request('GET', '/profile');

        self::assertResponseIsSuccessful();
        // Account dashboard shows the display name in the (a11y) heading + top bar.
        self::assertSelectorTextContains('h1', 'Hanne V');
        self::assertSelectorTextContains('.dtop', 'Hanne V');
    }

    public function testAuthenticatedUserCanViewSettings(): void
    {
        $client = static::createClient();

        $email = 'settings-view@example.com';
        $plain = $this->createUser($email, 'securepass12345!', 'Settings Rider');
        $this->loginAs($client, $email, $plain);

        $client->request('GET', '/settings');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Settings');
        // Profile form and password form should exist
        self::assertSelectorExists('input[name="settings[displayName]"]');
        self::assertSelectorExists('input[name="settings_password[currentPassword]"]');
        self::assertSelectorExists('input[name="settings_password[newPassword][first]"]');
        self::assertSelectorExists('input[name="settings_password[newPassword][second]"]');
    }

    // ── Settings persist tests ───────────────────────────────────────────────

    public function testSettingsUpdatePersistsDisplayNameAndPublicProfile(): void
    {
        $client = static::createClient();

        $email = 'settings-save@example.com';
        $plain = $this->createUser($email, 'securepass12345!', 'Old Name');
        $this->loginAs($client, $email, $plain);

        $crawler = $client->request('GET', '/settings');
        self::assertResponseIsSuccessful();

        // Submit the profile settings form
        $form = $crawler->selectButton('Save profile')->form([
            'settings[displayName]' => 'New Name',
            'settings[publicProfile]' => true,
        ]);
        $client->submit($form);

        // Should redirect back to /settings
        self::assertResponseRedirects('/settings');
        $client->followRedirect();
        self::assertResponseIsSuccessful();

        // Verify the changes persisted in the DB
        $user = $this->fetchUser($email);
        self::assertSame('New Name', $user->getDisplayName());
        self::assertTrue($user->isPublicProfile());
    }

    public function testSettingsUpdateCanTogglePublicProfileOff(): void
    {
        $client = static::createClient();

        $email = 'settings-toggle@example.com';
        $plain = $this->createUser($email, 'securepass12345!', 'Toggle Rider');

        // Set publicProfile = true first
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->fetchUser($email);
        $user->setPublicProfile(true);
        $em->flush();

        $this->loginAs($client, $email, $plain);

        $crawler = $client->request('GET', '/settings');
        self::assertResponseIsSuccessful();

        // Submit without publicProfile checkbox (should set to false)
        $form = $crawler->selectButton('Save profile')->form([
            'settings[displayName]' => 'Toggle Rider',
        ]);
        // Untick the checkbox explicitly
        $form['settings[publicProfile]']->untick();
        $client->submit($form);

        self::assertResponseRedirects('/settings');
        $client->followRedirect();
        self::assertResponseIsSuccessful();

        $updatedUser = $this->fetchUser($email);
        self::assertFalse($updatedUser->isPublicProfile());
    }

    public function testInvisibleCharacterInDisplayNameIsRejected(): void
    {
        $client = static::createClient();

        $email = 'settings-zwsp@example.com';
        $plain = $this->createUser($email, 'securepass12345!', 'Good Name');
        $this->loginAs($client, $email, $plain);

        $crawler = $client->request('GET', '/settings');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save profile')->form([
            'settings[displayName]' => "Bad\u{200B}Name", // zero-width space
        ]);
        $client->submit($form);

        // Same invisible-character guard as every other user text field.
        self::assertResponseStatusCodeSame(422);
        $user = $this->fetchUser($email);
        self::assertSame('Good Name', $user->getDisplayName(), 'display name must be unchanged');
    }

    // ── Password change tests ────────────────────────────────────────────────

    public function testWrongCurrentPasswordIsRejected(): void
    {
        $client = static::createClient();

        $email = 'pw-wrong@example.com';
        $plain = $this->createUser($email, 'securepass12345!', 'Pw Rider');
        $this->loginAs($client, $email, $plain);

        $crawler = $client->request('GET', '/settings');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Change password')->form([
            'settings_password[currentPassword]' => 'WRONGPASSWORD',
            'settings_password[newPassword][first]' => 'newsecurepass12!',
            'settings_password[newPassword][second]' => 'newsecurepass12!',
        ]);
        $client->submit($form);

        // Should redirect back with a password_error flash
        self::assertResponseRedirects('/settings');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        // Error flash must appear
        self::assertSelectorTextContains('.flash-error', 'Current password is incorrect');
    }

    public function testCorrectCurrentPasswordChangesPasswordAndCanRelogin(): void
    {
        $client = static::createClient();

        $email = 'pw-change@example.com';
        $oldPlain = 'securepass12345!';
        $newPlain = 'newsecurepass12!';
        $this->createUser($email, $oldPlain, 'Pw Change Rider');
        $this->loginAs($client, $email, $oldPlain);

        $crawler = $client->request('GET', '/settings');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Change password')->form([
            'settings_password[currentPassword]' => $oldPlain,
            'settings_password[newPassword][first]' => $newPlain,
            'settings_password[newPassword][second]' => $newPlain,
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/settings');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        // Success flash must appear
        self::assertSelectorTextContains('.flash-success', 'Password changed');

        // Can re-login with the NEW password (logout is POST + CSRF — submit the
        // account chip's log-out form)
        $client->submitForm('Log out');

        $this->loginAs($client, $email, $newPlain);
        $client->request('GET', '/profile');
        self::assertResponseIsSuccessful();
    }

    public function testPasswordChangeTooShortIsRejected(): void
    {
        $client = static::createClient();

        $email = 'pw-short@example.com';
        $plain = $this->createUser($email, 'securepass12345!', 'Short Pw Rider');
        $this->loginAs($client, $email, $plain);

        $crawler = $client->request('GET', '/settings');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Change password')->form([
            'settings_password[currentPassword]' => $plain,
            'settings_password[newPassword][first]' => 'short',
            'settings_password[newPassword][second]' => 'short',
        ]);
        $client->submit($form);

        // Symfony form validation fails → 422
        self::assertResponseStatusCodeSame(422);
    }

    public function testSettingsUpdatePersistsCountry(): void
    {
        $client = static::createClient();

        $em = static::getContainer()->get('doctrine')->getManager();
        $belgium = $em->getRepository(\App\World\Entity\Country::class)->findOneBy(['iso2' => 'BE']);
        if (null === $belgium) {
            self::markTestSkipped('World reference data not seeded — run app:world:import on the test DB.');
        }

        $email = 'country-save@example.com';
        $plain = $this->createUser($email, 'securepass12345!', 'Rider');
        $this->loginAs($client, $email, $plain);

        $crawler = $client->request('GET', '/settings');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('select[name="settings[country]"]');

        $form = $crawler->selectButton('Save profile')->form([
            'settings[displayName]' => 'Rider',
            'settings[country]' => (string) $belgium->getId(),
        ]);
        $client->submit($form);
        self::assertResponseRedirects('/settings');

        $user = $this->fetchUser($email);
        self::assertSame('BE', $user->getCountry()?->getIso2());
    }
}
