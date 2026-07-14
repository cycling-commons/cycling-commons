<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Tests\Controller;

use App\Catalog\BikeType;
use App\Catalog\RidingStyle;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * window.CC_PREFS emission on the map page (map prefilter spec 2026-07-14):
 * a logged-in rider's saved preferences ride the page render; anonymous
 * visitors get empty lists (zero behaviour change).
 *
 * Test isolation: DAMA\DoctrineTestBundle wraps each test in a rolled-back
 * transaction.
 */
final class MapPrefsTest extends WebTestCase
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
        $user->setBikeTypes([BikeType::Gravel, BikeType::Handbike]);
        $user->setRidingStyles([RidingStyle::Bikepacking]);
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

    public function testAnonymousGetsEmptyPrefs(): void
    {
        $client = static::createClient();
        $client->request('GET', '/map');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('window.CC_PREFS', $html);
        self::assertStringContainsString('{"bikes":[],"styles":[]}', $html);
    }

    public function testLoggedInRiderGetsSavedPrefs(): void
    {
        $client = static::createClient();
        $plain = $this->createUser('map-prefs@example.com', 'securepass12345!', 'Map Prefs Rider');
        $this->loginAs($client, 'map-prefs@example.com', $plain);

        $client->request('GET', '/map');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('"bikes":["Gravel","Handbike"]', $html);
        self::assertStringContainsString('"styles":["Bikepacking"]', $html);
    }
}
