<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Tests\Auth;

use App\Catalog\BikeType;
use App\Catalog\RidingStyle;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Rider preference selectors on /settings (spec 2026-07-14): bike types +
 * riding styles round-trip through the settings form as multi-select
 * checkbox groups; both are optional.
 *
 * Test isolation: DAMA\DoctrineTestBundle wraps each test in a rolled-back
 * transaction.
 */
final class RiderPreferencesTest extends WebTestCase
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

    // ── Tests ────────────────────────────────────────────────────────────────

    public function testSettingsPageShowsPreferenceCheckboxGroups(): void
    {
        $client = static::createClient();
        $plain = $this->createUser('prefs-view@example.com', 'securepass12345!', 'Prefs Viewer');
        $this->loginAs($client, 'prefs-view@example.com', $plain);

        $client->request('GET', '/settings');

        self::assertResponseIsSuccessful();
        // One checkbox per BikeType case (8) and per RidingStyle case (7).
        self::assertCount(
            \count(BikeType::cases()),
            $client->getCrawler()->filter('input[name="settings[bikeTypes][]"]'),
        );
        self::assertCount(
            \count(RidingStyle::cases()),
            $client->getCrawler()->filter('input[name="settings[ridingStyles][]"]'),
        );
    }

    public function testPreferencesRoundTripThroughSettingsForm(): void
    {
        $client = static::createClient();
        $plain = $this->createUser('prefs-save@example.com', 'securepass12345!', 'Prefs Saver');
        $this->loginAs($client, 'prefs-save@example.com', $plain);

        $crawler = $client->request('GET', '/settings');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save profile')->form();
        $form['settings[displayName]'] = 'Prefs Saver';
        // Checkbox groups index in enum declaration order:
        // bikeTypes: 0=Road 1=Gravel 2=MTB 3=E-bike 4=Handbike 5=Recumbent 6=Trike 7=Tandem
        // ridingStyles: 0=Road 1=Gravel 2=Touring 3=Bikepacking 4=Trail 5=Urban 6=Leisure
        $form['settings[bikeTypes]'][0]->tick();
        $form['settings[bikeTypes]'][1]->tick();
        $form['settings[ridingStyles]'][3]->tick();
        $client->submit($form);

        self::assertResponseRedirects('/settings');
        $client->followRedirect();
        self::assertResponseIsSuccessful();

        $user = $this->fetchUser('prefs-save@example.com');
        self::assertSame([BikeType::Road, BikeType::Gravel], $user->getBikeTypes());
        self::assertSame([RidingStyle::Bikepacking], $user->getRidingStyles());

        // The saved boxes come back pre-checked.
        $crawler = $client->request('GET', '/settings');
        self::assertCount(2, $crawler->filter('input[name="settings[bikeTypes][]"][checked]'));
        self::assertCount(1, $crawler->filter('input[name="settings[ridingStyles][]"][checked]'));
    }

    public function testEmptyPreferenceSelectionIsAllowedAndClearsSavedValues(): void
    {
        $client = static::createClient();
        $plain = $this->createUser('prefs-clear@example.com', 'securepass12345!', 'Prefs Clearer');

        // Pre-set preferences directly, then clear them via the form.
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->fetchUser('prefs-clear@example.com');
        $user->setBikeTypes([BikeType::Mtb]);
        $user->setRidingStyles([RidingStyle::Urban]);
        $em->flush();

        $this->loginAs($client, 'prefs-clear@example.com', $plain);

        $crawler = $client->request('GET', '/settings');
        $form = $crawler->selectButton('Save profile')->form();
        $form['settings[displayName]'] = 'Prefs Clearer';
        $form['settings[bikeTypes]'][2]->untick(); // MTB, pre-checked from setup
        $form['settings[ridingStyles]'][5]->untick(); // Urban, pre-checked from setup
        $client->submit($form);

        self::assertResponseRedirects('/settings');
        $user = $this->fetchUser('prefs-clear@example.com');
        self::assertSame([], $user->getBikeTypes());
        self::assertSame([], $user->getRidingStyles());
    }
}
