<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Locale preference across login (#32) and the switcher endpoint (#17).
 */
final class LocalePreferenceTest extends WebTestCase
{
    private function createUser(string $email, string $plain, ?string $locale): void
    {
        $c = static::getContainer();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $c->get(UserPasswordHasherInterface::class);
        /** @var EntityManagerInterface $em */
        $em = $c->get(EntityManagerInterface::class);

        $user = (new User())
            ->setEmail($email)
            ->setDisplayName('Loc Rider')
            ->setEmailVerified(true)
            ->setEmailVerifiedAt(new \DateTimeImmutable())
            ->setRoles([])
            ->setLocale($locale);
        $user->setPassword($hasher->hashPassword($user, $plain));
        $em->persist($user);
        $em->flush();
    }

    private function login(KernelBrowser $client, string $email, string $plain): void
    {
        $crawler = $client->request('GET', '/login');
        $form = $crawler->selectButton('Sign in')->form(['_username' => $email, '_password' => $plain]);
        $client->submit($form);
    }

    public function testSavedLocaleSurvivesLoginRedirect(): void
    {
        $client = static::createClient();
        $this->createUser('fr-rider@example.com', 'securepass12345!', 'fr');

        $this->login($client, 'fr-rider@example.com', 'securepass12345!');

        // The default target must land on the FR-prefixed profile, not /profile
        // (English), which would clobber the saved preference (#32).
        self::assertResponseRedirects('/fr/profile');
    }

    public function testEnglishUserLandsOnUnprefixedProfile(): void
    {
        $client = static::createClient();
        $this->createUser('en-rider@example.com', 'securepass12345!', 'en');

        $this->login($client, 'en-rider@example.com', 'securepass12345!');

        self::assertResponseRedirects('/profile');
    }

    public function testSwitcherDoesNotPersistToAccount(): void
    {
        $client = static::createClient();
        $this->createUser('switch-rider@example.com', 'securepass12345!', 'en');
        $this->login($client, 'switch-rider@example.com', 'securepass12345!');

        // A cross-site-triggerable GET must not mutate the stored account locale.
        $client->request('GET', '/i18n/de', ['to' => '/de']);

        /** @var UserRepository $repo */
        $repo = static::getContainer()->get(UserRepository::class);
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $user = $repo->findOneBy(['email' => 'switch-rider@example.com']);
        self::assertNotNull($user);
        self::assertSame('en', $user->getLocale(), 'the GET switcher must not persist to the account');
    }
}
