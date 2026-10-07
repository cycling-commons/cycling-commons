<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Tests\Auth;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The time zone on /account/settings (docs/specs/account-and-auth.md §9):
 * automatic by default, a chosen zone round-trips, and the page tells the
 * browser which zone to write times in and whether to report its own.
 */
final class TimeZonePreferenceTest extends WebTestCase
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

    public function testTheFieldOffersAutomaticFirstThenEveryZone(): void
    {
        $client = static::createClient();
        $plain = $this->createUser('tz-view@example.com', 'securepass12345!', 'TZ Viewer');
        $this->loginAs($client, 'tz-view@example.com', $plain);

        $crawler = $client->request('GET', '/account/settings');
        $options = $crawler->filter('select[name="settings[timeZone]"] option');
        self::assertSame('', $options->first()->attr('value'), 'automatic is the empty choice');
        self::assertCount(1 + \count(\DateTimeZone::listIdentifiers()), $options);
    }

    public function testAChosenZoneRoundTripsAndAutomaticStoresNone(): void
    {
        $client = static::createClient();
        $plain = $this->createUser('tz-save@example.com', 'securepass12345!', 'TZ Saver');
        $this->loginAs($client, 'tz-save@example.com', $plain);

        $form = $client->request('GET', '/account/settings')->selectButton('Save profile')->form();
        $form['settings[displayName]'] = 'TZ Saver';
        $form['settings[timeZone]'] = 'Europe/Lisbon';
        $client->submit($form);
        self::assertResponseRedirects('/account/settings');
        self::assertSame('Europe/Lisbon', $this->fetchUser('tz-save@example.com')->getTimeZone());

        $form = $client->request('GET', '/account/settings')->selectButton('Save profile')->form();
        $form['settings[timeZone]'] = '';
        $client->submit($form);
        self::assertNull($this->fetchUser('tz-save@example.com')->getTimeZone());
    }

    public function testThePageTellsTheBrowserItsZoneAndToReportItsOwnWhileAutomatic(): void
    {
        $client = static::createClient();
        $plain = $this->createUser('tz-body@example.com', 'securepass12345!', 'TZ Body');
        $this->loginAs($client, 'tz-body@example.com', $plain);

        $body = $client->request('GET', '/account/settings')->filter('body');
        self::assertSame('', $body->attr('data-cc-time-zone'), 'automatic: the browser uses its own');
        self::assertNotEmpty($body->attr('data-cc-tz-token'), 'automatic: the browser reports its zone');
        self::assertSame('', $body->attr('data-cc-tz-detected'));

        $form = $client->request('GET', '/account/settings')->selectButton('Save profile')->form();
        $form['settings[timeZone]'] = 'Europe/Lisbon';
        $client->submit($form);
        $body = $client->request('GET', '/account/settings')->filter('body');
        self::assertSame('Europe/Lisbon', $body->attr('data-cc-time-zone'));
        self::assertNull($body->attr('data-cc-tz-token'), 'a chosen zone is never overwritten by the browser');
    }

    /** Automatic names the zone it currently follows, once the browser has reported one. */
    public function testAutomaticShowsTheZoneTheBrowserReported(): void
    {
        $client = static::createClient();
        $plain = $this->createUser('tz-shown@example.com', 'securepass12345!', 'TZ Shown');
        $this->loginAs($client, 'tz-shown@example.com', $plain);

        $auto = $client->request('GET', '/account/settings')->filter('select[name="settings[timeZone]"] option')->first();
        self::assertStringNotContainsString('Europe', $auto->text(), 'nothing reported yet');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $em->getRepository(User::class)->findOneBy(['email' => 'tz-shown@example.com'])?->setDetectedTimeZone('Europe/Amsterdam');
        $em->flush();

        $auto = $client->request('GET', '/account/settings')->filter('select[name="settings[timeZone]"] option')->first();
        self::assertStringContainsString('Europe/Amsterdam', $auto->text());
    }
}
