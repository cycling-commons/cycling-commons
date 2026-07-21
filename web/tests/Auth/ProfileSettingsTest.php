<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Tests\Auth;

use App\Catalog\Entity\Region;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\BaseLocationService;
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
        self::assertResponseRedirects('/settings?tab=security');
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

        self::assertResponseRedirects('/settings?tab=security');
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

    // ── Base-location tests (region-scoping-design.md §4) ────────────────────

    /**
     * Region fixture in the open mid-Atlantic (BaseAreaResolverTest's box
     * idiom) so derivation never collides with real seeded region data.
     * Contains the coarsened probe point (0.45, -45.85) used below.
     */
    private function makeBaseRegion(): Region
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $region = new Region();
        $region->setSlug('settings-base-test-'.bin2hex(random_bytes(4)))
            ->setName('Settings base test box')
            ->setCountryCode('BE')
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[-46.00,0.30],[-45.00,0.30],[-45.00,0.60],[-46.00,0.60],[-46.00,0.30]]]]}');
        $em->persist($region);
        $em->flush();

        return $region;
    }

    public function testBaseLocationRoundTripThroughSettingsForm(): void
    {
        $client = static::createClient();
        $this->makeBaseRegion();

        $email = 'base-round@example.com';
        $plain = $this->createUser($email, 'securepass12345!', 'Base Rider');
        $this->loginAs($client, $email, $plain);

        $crawler = $client->request('GET', '/settings');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save profile')->form([
            'settings[displayName]' => 'Base Rider',
            'settings[baseLat]' => '0.451234',
            'settings[baseLng]' => '-45.851234',
            'settings[basePlace]' => 'Namur',
            'settings[baseRadiusKm]' => '60',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/settings');
        $client->followRedirect();
        self::assertResponseIsSuccessful();

        $user = $this->fetchUser($email);
        self::assertSame(0.45, $user->getBaseLat());
        self::assertSame(-45.85, $user->getBaseLng());
        self::assertSame(60, $user->getBaseRadiusKm());
        self::assertSame('Namur', $user->getBasePlace());
        self::assertNotSame([], $user->getBaseRegionIds(), 'derivation should find the fixture region');
    }

    public function testBaseClearRemovesLocation(): void
    {
        $client = static::createClient();
        $this->makeBaseRegion();

        $email = 'base-clear@example.com';
        $plain = $this->createUser($email, 'securepass12345!', 'Base Rider');
        $this->loginAs($client, $email, $plain);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->fetchUser($email);
        $svc = static::getContainer()->get(BaseLocationService::class);
        $svc->apply($user, 0.451234, -45.851234, 'Namur', 60);
        $em->flush();
        self::assertTrue($user->hasBaseLocation());

        $crawler = $client->request('GET', '/settings');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save profile')->form([
            'settings[displayName]' => 'Base Rider',
            'settings[baseClear]' => true,
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/settings');
        $client->followRedirect();
        self::assertResponseIsSuccessful();

        $updated = $this->fetchUser($email);
        self::assertFalse($updated->hasBaseLocation());
        self::assertSame([], $updated->getBaseRegionIds());
    }

    public function testRadiusOnlyChangeRederives(): void
    {
        $client = static::createClient();
        $this->makeBaseRegion();

        $email = 'base-radius@example.com';
        $plain = $this->createUser($email, 'securepass12345!', 'Base Rider');
        $this->loginAs($client, $email, $plain);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->fetchUser($email);
        $svc = static::getContainer()->get(BaseLocationService::class);
        $svc->apply($user, 0.451234, -45.851234, 'Namur', 40);
        $em->flush();

        $crawler = $client->request('GET', '/settings');
        self::assertResponseIsSuccessful();

        // Hidden lat/lng/place fields stay empty — only the slider moves — so
        // the controller must re-derive from the STORED point, not the form.
        $form = $crawler->selectButton('Save profile')->form([
            'settings[displayName]' => 'Base Rider',
            'settings[baseRadiusKm]' => '120',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/settings');
        $client->followRedirect();
        self::assertResponseIsSuccessful();

        $updated = $this->fetchUser($email);
        self::assertSame(120, $updated->getBaseRadiusKm());
        self::assertSame('Namur', $updated->getBasePlace(), 'place is preserved across a radius-only change');
        self::assertSame(0.45, $updated->getBaseLat(), 'stored point is unchanged by a radius-only submit');
        self::assertNotSame([], $updated->getBaseRegionIds(), 'derivation re-ran against the wider radius');
    }

    public function testGarbageCoordsIgnored(): void
    {
        $client = static::createClient();

        $email = 'base-garbage@example.com';
        $plain = $this->createUser($email, 'securepass12345!', 'Old Name');
        $this->loginAs($client, $email, $plain);

        $crawler = $client->request('GET', '/settings');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save profile')->form([
            'settings[displayName]' => 'New Name',
            'settings[baseLat]' => 'abc',
            'settings[baseLng]' => 'xyz',
        ]);
        $client->submit($form);

        // Garbage coords are silently ignored — the rest of the form still saves.
        self::assertResponseRedirects('/settings');
        $client->followRedirect();
        self::assertResponseIsSuccessful();

        $user = $this->fetchUser($email);
        self::assertSame('New Name', $user->getDisplayName());
        self::assertFalse($user->hasBaseLocation());
    }
}
