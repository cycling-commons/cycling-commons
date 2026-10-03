<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Entity\User;
use App\Security\FormGuard;
use App\Security\PseudonymousKey;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The display-name hint (account-and-auth.md §9): whether another account
 * already uses a name. Information only, and names only: the answer is one
 * boolean, an address is never checkable, and the sign-up page answers a
 * taken address and a new one alike.
 *
 * Test isolation: DAMA\DoctrineTestBundle wraps each test in a rolled-back
 * transaction.
 */
final class DisplayNameHintTest extends WebTestCase
{
    use GuardedSignupTrait;

    private const string PASSWORD = 'securepass12345!';

    private function client(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    private function createUser(string $email, string $displayName, bool $public = true): User
    {
        $container = static::getContainer();
        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName($displayName);
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setPublicProfile($public);
        $user->setRoles([]);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));

        $em = $container->get(EntityManagerInterface::class);
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function stamp(): string
    {
        return static::getContainer()->get(FormGuard::class)->stamp(new \DateTimeImmutable());
    }

    /**
     * @param array<string, string> $fields
     *
     * @return array<string, mixed>
     */
    private function ask(KernelBrowser $client, array $fields): array
    {
        $client->request('POST', '/register/name-check', $fields);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame(['inUse'], array_keys($body), 'The answer is one boolean, never a name, an id or a count.');

        return $body;
    }

    public function testAnAnonymousVisitorWithTheSignUpStampGetsTheAnswer(): void
    {
        $client = $this->client();
        $this->createUser('jan@example.com', 'Jan Peeters');

        self::assertSame(['inUse' => true], $this->ask($client, ['name' => 'Jan Peeters', 'form_stamp' => $this->stamp()]));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));

        // Case and edge spaces are the same name to a reader.
        self::assertSame(['inUse' => true], $this->ask($client, ['name' => '  jan PEETERS ', 'form_stamp' => $this->stamp()]));
        self::assertSame(['inUse' => false], $this->ask($client, ['name' => 'Jan Peeters-Maes', 'form_stamp' => $this->stamp()]));
    }

    /** A private rider's name is on no pin and no wall, so the check never confirms it. */
    public function testAPrivateProfileIsNeverCounted(): void
    {
        $client = $this->client();
        $this->createUser('hidden@example.com', 'Hidden Rider', public: false);

        self::assertSame(['inUse' => false], $this->ask($client, ['name' => 'Hidden Rider', 'form_stamp' => $this->stamp()]));
    }

    public function testAnAnonymousVisitorWithoutALiveStampGetsNoAnswer(): void
    {
        $client = $this->client();
        $this->createUser('jan@example.com', 'Jan Peeters');

        self::assertSame(['inUse' => null], $this->ask($client, ['name' => 'Jan Peeters']));
        self::assertResponseStatusCodeSame(403);

        self::assertSame(['inUse' => null], $this->ask($client, ['name' => 'Jan Peeters', 'form_stamp' => '123.forged']));
        self::assertResponseStatusCodeSame(403);

        $stale = static::getContainer()->get(FormGuard::class)->stamp(new \DateTimeImmutable('-'.(FormGuard::MAX_SECONDS + 60).' seconds'));
        self::assertSame(['inUse' => null], $this->ask($client, ['name' => 'Jan Peeters', 'form_stamp' => $stale]));
        self::assertResponseStatusCodeSame(403);
    }

    /** Only display names are compared: an address is never checkable here. */
    public function testAnAddressIsNeverFound(): void
    {
        $client = $this->client();
        $this->createUser('jan@example.com', 'Jan Peeters');

        self::assertSame(['inUse' => false], $this->ask($client, ['name' => 'jan@example.com', 'form_stamp' => $this->stamp()]));
    }

    public function testASignedInRiderIsNotCountedAgainstThemselves(): void
    {
        $client = $this->client();
        $me = $this->createUser('me@example.com', 'Solo Rider');
        $this->createUser('twin@example.com', 'Shared Name');
        $client->loginUser($me);

        self::assertSame(['inUse' => false], $this->ask($client, ['name' => 'Solo Rider']));
        self::assertSame(['inUse' => true], $this->ask($client, ['name' => 'shared name']));
    }

    public function testANameTheFormsWouldRefuseByLengthGetsNoAnswer(): void
    {
        $client = $this->client();

        self::assertSame(['inUse' => null], $this->ask($client, ['name' => ' J ', 'form_stamp' => $this->stamp()]));
        self::assertSame(['inUse' => null], $this->ask($client, ['name' => str_repeat('a', 101), 'form_stamp' => $this->stamp()]));
    }

    /**
     * Drained through the factory: the array pool behind the limiter is reset
     * at the start of every test request after the first (see
     * CoverageQueryTest::testOverLimitIs429), so the one HTTP request here is
     * the 31st.
     */
    public function testOneConnectionIsLimited(): void
    {
        $client = $this->client();
        $container = static::getContainer();
        $secret = $container->getParameter('kernel.secret');
        self::assertIsString($secret);
        $limiter = $container->get('limiter.display_name_check')
            ->create(PseudonymousKey::limiter('display_name_check', '127.0.0.1', $secret));
        for ($i = 0; $i < 30; ++$i) {
            self::assertTrue($limiter->consume()->isAccepted());
        }

        self::assertSame(['inUse' => null], $this->ask($client, ['name' => 'Rider 31', 'form_stamp' => $this->stamp()]));
        self::assertResponseStatusCodeSame(429);
    }

    public function testTheSignUpPageCarriesTheLiveHint(): void
    {
        $client = $this->client();
        $client->request('GET', '/register');

        self::assertSelectorExists('#registration_form_displayName_hint[data-name-hint][data-stamp][hidden]');
        self::assertSelectorExists('#registration_form_displayName[aria-describedby="registration_form_displayName_hint"]');
    }

    /**
     * Without JavaScript the hint comes after the submit, on the "check your
     * email" page, and it is the same for a taken address as for a new one.
     */
    public function testTheHintAfterSignUpIsTheSameForATakenAddressAndANewOne(): void
    {
        $client = $this->client();
        $this->createUser('taken@example.com', 'Jan Peeters');

        $this->signUp($client, 'taken@example.com', 'Jan Peeters');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.name-hint', 'Another rider already uses this name. You can still use it.');

        $this->signUp($client, 'fresh@example.com', 'Jan Peeters');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.name-hint', 'Another rider already uses this name. You can still use it.');

        // A free name needs no message (owner 2026-10-03).
        $this->signUp($client, 'other@example.com', 'Unique Rider Name');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.name-hint[data-state]');
    }

    public function testSettingsShowTheHintWithoutJavaScript(): void
    {
        $client = $this->client();
        $me = $this->createUser('me@example.com', 'Solo Rider');
        $client->loginUser($me);

        $client->request('GET', '/account/settings');
        self::assertResponseIsSuccessful();
        // A free name needs no message (owner 2026-10-03).
        self::assertSelectorExists('#settings_displayName_hint[hidden]');
        self::assertSelectorNotExists('#settings_displayName_hint[data-state]');

        $this->createUser('twin@example.com', 'solo rider');
        $client->request('GET', '/account/settings');
        self::assertSelectorTextContains('#settings_displayName_hint', 'Another rider already uses this name. You can still use it.');
        // A notice, not an error: atlas.css colours [data-state="shared"] in --clay.
        self::assertSelectorExists('#settings_displayName_hint[data-state="shared"]');
    }
}
