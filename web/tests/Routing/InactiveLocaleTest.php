<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Routing;

use App\Entity\User;
use App\Routing\ActiveLocales;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A language this deployment does not serve is not reachable and not
 * offered, however a reader arrives at it (dev-environment.md §7 i18n).
 *
 * The service is swapped BEFORE the first request: the subscriber and the
 * Twig extension take it as a constructor argument, so a container set()
 * after either has been built would change nothing.
 */
final class InactiveLocaleTest extends WebTestCase
{
    private const array ENABLED = ['en', 'fr', 'nl', 'de', 'es'];

    public function testAPrefixedPathInAnInactiveLanguageRedirectsToTheSamePageInEnglish(): void
    {
        $client = static::createClient();
        $this->serve(['en', 'nl']);

        $client->request('GET', '/de/ueber-uns?ref=old');
        self::assertResponseRedirects('/about?ref=old', 302);
    }

    public function testTheInactiveHomePageRedirectsToTheEnglishHomePage(): void
    {
        $client = static::createClient();
        $this->serve(['en', 'nl']);

        $client->request('GET', '/fr/');
        self::assertResponseRedirects('/', 302);
    }

    public function testAnActiveLanguageStillServesItsPrefixedPath(): void
    {
        $client = static::createClient();
        $this->serve(['en', 'nl']);

        $client->request('GET', '/nl/');
        self::assertResponseIsSuccessful();
    }

    public function testTheLanguageMenuOffersOnlyTheServedLanguages(): void
    {
        $client = static::createClient();
        $this->serve(['en', 'nl']);

        $crawler = $client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $hrefs = $crawler->filter('.lang-dropdown a')->each(
            static fn ($node): string => (string) $node->attr('href'),
        );
        self::assertNotSame([], $hrefs, 'the menu must still render');
        foreach (['/i18n/fr', '/i18n/de', '/i18n/es'] as $gone) {
            foreach ($hrefs as $href) {
                self::assertStringNotContainsString($gone, $href);
            }
        }
        self::assertTrue(
            (bool) array_filter($hrefs, static fn (string $h): bool => str_contains($h, '/i18n/nl')),
            'Dutch is served, so it is offered',
        );
    }

    public function testSwitchingToAnInactiveLanguageSwitchesToEnglish(): void
    {
        $client = static::createClient();
        $this->serve(['en', 'nl']);

        $client->disableReboot();
        $client->request('GET', '/i18n/fr?to=/about');
        self::assertResponseRedirects('/i18n/en?to=%2Fabout', 302);

        $client->followRedirect();
        self::assertResponseRedirects('/about');
        self::assertSame('en', $client->getRequest()->getSession()->get('_locale'));
    }

    public function testTheHreflangBlockNamesOnlyTheServedLanguages(): void
    {
        $client = static::createClient();
        $this->serve(['en', 'nl']);

        $crawler = $client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $langs = $crawler->filter('link[rel="alternate"][hreflang]')->each(
            static fn ($node): string => (string) $node->attr('hreflang'),
        );
        self::assertNotContains('fr', $langs);
        self::assertNotContains('de', $langs);
        self::assertNotContains('es', $langs);
    }

    /**
     * A language nobody may read is not a language anybody may translate:
     * offering it would fill a catalogue whose only door answers 404.
     */
    public function testTheTranslateChooserOffersOnlyTheServedLanguages(): void
    {
        $client = static::createClient();
        $this->serve(['en', 'nl']);
        $client->loginUser($this->rider('inactive-locale-translate@example.com'));

        $crawler = $client->request('GET', '/translate');
        self::assertResponseIsSuccessful();

        $codes = $crawler->filter('.chooser .loc')->each(
            static fn ($node): string => strtolower(trim((string) $node->text())),
        );
        self::assertSame(['nl'], $codes);
    }

    private function rider(string $email): User
    {
        $container = static::getContainer();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName(strstr($email, '@', true) ?: $email);
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setPassword($hasher->hashPassword($user, 'hunter2secure!'));
        $em->persist($user);
        $em->flush();

        return $user;
    }

    /** @param list<string> $active */
    private function serve(array $active): void
    {
        static::getContainer()->set(
            ActiveLocales::class,
            new ActiveLocales('en', self::ENABLED, $active),
        );
    }
}
