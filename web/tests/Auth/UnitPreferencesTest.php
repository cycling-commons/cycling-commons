<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Tests\Auth;

use App\Account\DistanceUnit;
use App\Account\ElevationUnit;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Kilometres-or-miles and metres-or-feet on /settings
 * (docs/specs/account-and-auth.md §9): the two dropdowns round-trip, and what
 * they change is the page a rider then reads — not anything stored.
 *
 * Test isolation: DAMA\DoctrineTestBundle wraps each test in a rolled-back
 * transaction.
 */
final class UnitPreferencesTest extends WebTestCase
{
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

        $client->submit($crawler->selectButton('Sign in')->form([
            '_username' => $email,
            '_password' => $plain,
        ]));

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

    public function testANewAccountReadsMetric(): void
    {
        $client = static::createClient();
        $plain = $this->createUser('units-default@example.com', 'securepass12345!', 'Units Default');
        $this->loginAs($client, 'units-default@example.com', $plain);

        $user = $this->fetchUser('units-default@example.com');
        self::assertSame(DistanceUnit::Km, $user->getDistanceUnit());
        self::assertSame(ElevationUnit::M, $user->getElevationUnit());
    }

    public function testTheSettingsPageOffersBothDropdowns(): void
    {
        $client = static::createClient();
        $plain = $this->createUser('units-view@example.com', 'securepass12345!', 'Units Viewer');
        $this->loginAs($client, 'units-view@example.com', $plain);

        $crawler = $client->request('GET', '/settings');
        self::assertResponseIsSuccessful();

        self::assertCount(
            \count(DistanceUnit::cases()),
            $crawler->filter('select[name="settings[distanceUnit]"] option'),
        );
        self::assertCount(
            \count(ElevationUnit::cases()),
            $crawler->filter('select[name="settings[elevationUnit]"] option'),
        );
    }

    /**
     * Distance and climbing are asked separately, so the combination most of
     * Britain rides — miles with metres — has to survive a save.
     */
    public function testMilesWithMetresRoundTripsThroughTheForm(): void
    {
        $client = static::createClient();
        $plain = $this->createUser('units-save@example.com', 'securepass12345!', 'Units Saver');
        $this->loginAs($client, 'units-save@example.com', $plain);

        $crawler = $client->request('GET', '/settings');
        $form = $crawler->selectButton('Save profile')->form();
        $form['settings[displayName]'] = 'Units Saver';
        $form['settings[distanceUnit]'] = DistanceUnit::Mi->value;
        $form['settings[elevationUnit]'] = ElevationUnit::M->value;
        $client->submit($form);

        self::assertResponseRedirects('/settings');
        $client->followRedirect();

        $user = $this->fetchUser('units-save@example.com');
        self::assertSame(DistanceUnit::Mi, $user->getDistanceUnit());
        self::assertSame(ElevationUnit::M, $user->getElevationUnit());

        // And the saved choice comes back selected.
        $crawler = $client->request('GET', '/settings');
        self::assertSame(
            'mi',
            $crawler->filter('select[name="settings[distanceUnit]"] option[selected]')->attr('value'),
        );
    }

    /**
     * The preference is only worth having if the page changes with it. The
     * client half is handed the same two values the Twig filters use, so a
     * distance drawn by the map cannot disagree with one rendered beside it.
     */
    public function testThePageHandsTheRidersUnitsToTheClient(): void
    {
        $client = static::createClient();
        $plain = $this->createUser('units-bridge@example.com', 'securepass12345!', 'Units Bridge');
        $this->loginAs($client, 'units-bridge@example.com', $plain);

        $client->request('GET', '/settings');
        // On <body>, not in a script: boot.js is one shared file for everyone
        // and reads the two per-rider values from there (page-caching.md §3.2).
        self::assertStringContainsString('data-cc-distance-unit="km"', (string) $client->getResponse()->getContent());

        $crawler = $client->request('GET', '/settings');
        $form = $crawler->selectButton('Save profile')->form();
        $form['settings[displayName]'] = 'Units Bridge';
        $form['settings[distanceUnit]'] = DistanceUnit::Mi->value;
        $form['settings[elevationUnit]'] = ElevationUnit::Ft->value;
        $client->submit($form);
        $client->followRedirect();

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-cc-distance-unit="mi"', $html);
        self::assertStringContainsString('data-cc-elevation-unit="ft"', $html);
    }

    /** The radius read-out beside the base-location slider follows it too. */
    public function testAServerRenderedDistanceIsWrittenInTheChosenUnit(): void
    {
        $client = static::createClient();
        $plain = $this->createUser('units-render@example.com', 'securepass12345!', 'Units Render');
        $this->loginAs($client, 'units-render@example.com', $plain);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->fetchUser('units-render@example.com');
        $user->setBaseRadiusKm(80);
        $em->flush();

        $crawler = $client->request('GET', '/settings');
        self::assertSame('80 km', trim($crawler->filter('output[data-radius-output]')->text()));

        $form = $crawler->selectButton('Save profile')->form();
        $form['settings[displayName]'] = 'Units Render';
        $form['settings[distanceUnit]'] = DistanceUnit::Mi->value;
        $client->submit($form);
        $crawler = $client->followRedirect();

        // 80 km is 50 miles; what is STORED is still 80.
        self::assertSame('50 mi', trim($crawler->filter('output[data-radius-output]')->text()));
        self::assertSame(80, $this->fetchUser('units-render@example.com')->getBaseRadiusKm());
    }
}
