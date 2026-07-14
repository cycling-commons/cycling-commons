<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Tests\Auth;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Settings two-tab layout (Profile / Security, spec 2026-07-14): default tab,
 * ?tab=security activation, and a failed password submission landing on the
 * Security tab so the errors are visible.
 *
 * Test isolation: DAMA\DoctrineTestBundle wraps each test in a rolled-back
 * transaction.
 */
final class SettingsTabsTest extends WebTestCase
{
    // ── Helpers (same pattern as DisplayNameUniquenessTest) ──────────────────

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

    public function testProfileTabIsDefault(): void
    {
        $client = static::createClient();
        $plain = $this->createUser('tabs-default@example.com', 'securepass12345!', 'Tabs Rider');
        $this->loginAs($client, 'tabs-default@example.com', $plain);

        $client->request('GET', '/settings');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#tabbtn-profile[aria-selected="true"]');
        self::assertSelectorExists('#tabbtn-security[aria-selected="false"]');
        self::assertSelectorExists('#tab-profile.on');
        self::assertSelectorNotExists('#tab-security.on');
    }

    public function testQueryParamActivatesSecurityTab(): void
    {
        $client = static::createClient();
        $plain = $this->createUser('tabs-sec@example.com', 'securepass12345!', 'Tabs Sec Rider');
        $this->loginAs($client, 'tabs-sec@example.com', $plain);

        $client->request('GET', '/settings?tab=security');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#tabbtn-security[aria-selected="true"]');
        self::assertSelectorExists('#tab-security.on');
        self::assertSelectorNotExists('#tab-profile.on');
    }

    public function testWrongPasswordLandsOnSecurityTab(): void
    {
        $client = static::createClient();
        $plain = $this->createUser('tabs-pw@example.com', 'securepass12345!', 'Tabs Pw Rider');
        $this->loginAs($client, 'tabs-pw@example.com', $plain);

        $crawler = $client->request('GET', '/settings');
        $form = $crawler->selectButton('Change password')->form([
            'settings_password[currentPassword]' => 'WRONG',
            'settings_password[newPassword][first]' => 'newsecurepass12!',
            'settings_password[newPassword][second]' => 'newsecurepass12!',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/settings?tab=security');
        $client->followRedirect();
        self::assertSelectorExists('#tab-security.on');
        self::assertSelectorTextContains('.flash-error', 'Current password is incorrect');
    }
}
