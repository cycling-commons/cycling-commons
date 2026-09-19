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

        // The default target must land on the FR-prefixed profile, not /account/contributions
        // (English), which would clobber the saved preference (#32).
        self::assertResponseRedirects('/fr/account/contributions');
    }

    public function testEnglishUserLandsOnUnprefixedProfile(): void
    {
        $client = static::createClient();
        $this->createUser('en-rider@example.com', 'securepass12345!', 'en');

        $this->login($client, 'en-rider@example.com', 'securepass12345!');

        self::assertResponseRedirects('/account/contributions');
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

    /**
     * The locale switcher's Referer fallback is same-ORIGIN, not same-prefix.
     *
     * It used to accept any Referer that STARTED WITH the site's own
     * scheme-and-host, so `https://localhost.evil.example/` passed the check
     * and the switcher happily bounced the visitor off-site (security scan
     * 2026-08-25). A locale link is an ordinary GET, so an attacker only needed
     * a page that links `/i18n/en` with their own Referer.
     *
     * The `?to=` branch is checked first and is a separate allowlist, so these
     * cases deliberately send no `to` at all.
     *
     * @param non-empty-string $referer
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('hostileReferers')]
    public function testAForeignRefererNeverRedirectsOffSite(string $referer): void
    {
        $client = static::createClient();
        $client->request('GET', '/i18n/de', [], [], ['HTTP_REFERER' => $referer]);

        // The home route is locale-prefixed, so switching to German lands on /de/.
        self::assertResponseRedirects('/de/', null, sprintf('%s must fall back home', $referer));
    }

    /** @return iterable<string, array{string}> */
    public static function hostileReferers(): iterable
    {
        yield 'host is a prefix of the attacker domain' => ['http://localhost.evil.example/pwned'];
        yield 'host continues past a dot' => ['http://localhost.evil.example:8080/pwned'];
        yield 'userinfo dresses up the real host' => ['http://localhost@evil.example/pwned'];
        yield 'plain foreign host' => ['http://evil.example/pwned'];
        yield 'protocol-relative' => ['//evil.example/pwned'];
    }

    /** And a genuine same-origin Referer still works, which is the point of the branch. */
    public function testAnOwnSiteRefererStillRedirectsBack(): void
    {
        $client = static::createClient();
        $client->request('GET', '/i18n/de', [], [], ['HTTP_REFERER' => 'http://localhost/regions']);

        self::assertResponseRedirects('http://localhost/regions');
    }
}
