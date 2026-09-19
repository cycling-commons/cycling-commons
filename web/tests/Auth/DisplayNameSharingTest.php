<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Display names are labels, not identifiers (account-and-auth.md §9). Two
 * riders really can both be called John Doe — it is a common name, and telling
 * the second one "that name is taken" would be telling them their own name is
 * somebody else's property. The uuid is what distinguishes accounts, and it is
 * the only thing any public surface keys on.
 *
 * This replaces the former DisplayNameUniquenessTest, whose expectations are
 * now inverted by decision rather than by regression.
 *
 * Test isolation: DAMA\DoctrineTestBundle wraps each test in a rolled-back
 * transaction.
 */
final class DisplayNameSharingTest extends WebTestCase
{
    private const string PASSWORD = 'securepass12345!';

    private function createUser(string $email, string $displayName): User
    {
        $container = static::getContainer();
        $hasher = $container->get(UserPasswordHasherInterface::class);
        $em = $container->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName($displayName);
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles([]);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));

        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function loginAs(KernelBrowser $client, string $email): void
    {
        $crawler = $client->request('GET', '/login');
        self::assertResponseIsSuccessful();

        $client->submit($crawler->selectButton('Sign in')->form([
            '_username' => $email,
            '_password' => self::PASSWORD,
        ]));
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    private function fetchUser(string $email): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    public function testTwoAccountsMayCarryTheSameNameAtTheDatabaseLevel(): void
    {
        self::createClient();

        $first = $this->createUser('john.doe.1@example.test', 'John Doe');
        $second = $this->createUser('john.doe.2@example.test', 'John Doe');

        self::assertSame('John Doe', $first->getDisplayName());
        self::assertSame('John Doe', $second->getDisplayName());
        self::assertNotSame(
            $first->getUuid()?->toRfc4122(),
            $second->getUuid()?->toRfc4122(),
            'the uuid is what tells them apart',
        );
    }

    public function testRegistrationAcceptsANameSomebodyElseAlreadyUses(): void
    {
        $client = static::createClient();
        $this->createUser('incumbent@example.test', 'John Doe');

        $crawler = $client->request('GET', '/register');
        self::assertResponseIsSuccessful();

        $client->submit($crawler->selectButton('Create account')->form([
            'registration_form[email]' => 'newcomer@example.test',
            'registration_form[displayName]' => 'John Doe',
            'registration_form[plainPassword][first]' => self::PASSWORD,
            'registration_form[plainPassword][second]' => self::PASSWORD,
            'registration_form[confirmAge]' => true,
            'registration_form[agreeTerms]' => true,
        ]));

        // Registration lands on the check-your-email page, not a redirect.
        self::assertResponseIsSuccessful();
        self::assertSame('John Doe', $this->fetchUser('newcomer@example.test')->getDisplayName());
    }

    public function testSettingsAcceptsRenamingToANameSomebodyElseUses(): void
    {
        $client = static::createClient();
        $this->createUser('incumbent2@example.test', 'John Doe');
        $this->createUser('renamer@example.test', 'Someone Else');

        $this->loginAs($client, 'renamer@example.test');
        $crawler = $client->request('GET', '/account/settings');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[name="settings"]')->form();
        $form['settings[displayName]'] = 'John Doe';
        $client->submit($form);

        self::assertResponseRedirects();
        self::assertSame('John Doe', $this->fetchUser('renamer@example.test')->getDisplayName());
    }

    /**
     * Case variants were the original motivation for the uniqueness rule.
     * They are now simply two riders who capitalise differently.
     */
    public function testCaseVariantsCoexist(): void
    {
        self::createClient();

        $this->createUser('caps@example.test', 'John Doe');
        $this->createUser('lower@example.test', 'john doe');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $names = $em->getRepository(User::class)->createQueryBuilder('u')
            ->select('u.displayName')
            ->where('u.email IN (:emails)')
            ->setParameter('emails', ['caps@example.test', 'lower@example.test'])
            ->orderBy('u.email', 'ASC')
            ->getQuery()->getSingleColumnResult();

        self::assertSame(['John Doe', 'john doe'], $names);
    }

    /**
     * The name is a label; the public profile is keyed on the uuid. Two riders
     * sharing a name still have two distinct, separately reachable profiles —
     * which is what makes attribution unambiguous everywhere it matters,
     * photo credits included (photo-uploads.md §1.3c).
     */
    public function testEachNamesakeKeepsTheirOwnPublicProfile(): void
    {
        $client = static::createClient();

        $first = $this->createUser('namesake.a@example.test', 'John Doe');
        $second = $this->createUser('namesake.b@example.test', 'John Doe');
        $first->setPublicProfile(true);
        $second->setPublicProfile(true);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        foreach ([$first, $second] as $rider) {
            $client->request('GET', '/riders/'.$rider->getUuid()?->toRfc4122());
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'John Doe');
        }
    }
}
