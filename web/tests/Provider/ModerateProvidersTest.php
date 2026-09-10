<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Provider;

use App\Entity\User;
use App\Provider\Entity\DataProvider;
use App\Provider\Exception\ProviderRuleException;
use App\Provider\ProviderRegistry;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The curator Providers desk, and what it refuses
 * (data-provider-hierarchy.md §8).
 */
final class ModerateProvidersTest extends WebTestCase
{
    public function testTheDeskNeedsACurator(): void
    {
        $client = static::createClient();
        $client->loginUser($this->user('provider-desk-rider@example.com'));

        $client->request('GET', '/moderate/providers');
        self::assertResponseStatusCodeSame(403);
    }

    public function testACuratorSeesEveryProviderWithItsState(): void
    {
        $client = static::createClient();
        $client->loginUser($this->user('provider-desk-curator@example.com', ['ROLE_CURATOR']));

        $crawler = $client->request('GET', '/moderate/providers');
        self::assertResponseIsSuccessful();

        $keys = $crawler->filter('.pv-key')->each(static fn ($n): string => trim((string) $n->text()));
        self::assertContains('osm', $keys);
        self::assertContains('wallonie-pivot', $keys);
        // OpenStreetMap is a system row, and the page says so rather than
        // offering a control that would be refused.
        self::assertGreaterThan(0, $crawler->filter('input[name="rank"][readonly]')->count());

        // The source shows, read-only, with the run commands (owner 2026-09-05:
        // "show the source and the run command"); a built-in row has none.
        $src = $crawler->filter('[data-provider-source="rivm-drinkwater"]');
        self::assertSame(1, $src->count());
        self::assertStringContainsString('https://data.rivm.nl/geo/alo/wfs', $src->text());
        self::assertStringContainsString('alo:rivm_drinkwaterkranen_actueel', $src->text());
        self::assertStringContainsString('providers.run --key rivm-drinkwater', $src->filter('pre')->text());
        self::assertStringContainsString('app:providers:harvest rivm-drinkwater /tmp/rivm-drinkwater.json --write', $src->filter('pre')->text());
        self::assertSame(0, $crawler->filter('[data-provider-source="osm"]')->count(), 'a built-in row has no fetchable source');
    }

    /**
     * The rule this registry exists for: serving rows from a dataset whose
     * licence names a notice, while showing none, is the licence breach.
     */
    public function testEnablingAProviderThatOwesANoticeWithoutOneIsRefused(): void
    {
        self::bootKernel();
        $provider = $this->seed('test-owes', 'cc-by-4.0');

        $this->expectException(ProviderRuleException::class);
        $this->expectExceptionMessage('provider.error.attribution_required');

        $this->registry()->update($provider, ['attribution' => '', 'enabled' => true], $this->actor());
    }

    public function testTheSameProviderSavesOnceItCarriesTheNotice(): void
    {
        self::bootKernel();
        $provider = $this->seed('test-owes-ok', 'cc-by-4.0');

        $this->registry()->update($provider, ['attribution' => '© Somebody', 'enabled' => true], $this->actor());

        self::assertTrue($provider->isEnabled());
        self::assertSame('© Somebody', $provider->getAttribution());
    }

    /** A public-domain dataset owes nothing, so nothing blocks it. */
    public function testAProviderThatOwesNothingEnablesWithNoNotice(): void
    {
        self::bootKernel();
        $provider = $this->seed('test-owes-nothing', 'cc0-1.0');

        $this->registry()->update($provider, ['attribution' => '', 'enabled' => true], $this->actor());

        self::assertTrue($provider->isEnabled());
    }

    /**
     * Moving OpenStreetMap would silently reorder every duplicate decision on
     * the map, which is not a thing a form should be able to do.
     */
    public function testASystemRowKeepsItsRank(): void
    {
        self::bootKernel();
        $osm = $this->registry()->all();
        $osm = current(array_filter($osm, static fn (DataProvider $p): bool => 'osm' === $p->getKey()));
        self::assertInstanceOf(DataProvider::class, $osm);

        $this->expectException(ProviderRuleException::class);
        $this->expectExceptionMessage('provider.error.system_rank');

        $this->registry()->update($osm, ['rank' => 999], $this->actor());
    }

    public function testASystemRowCannotBeDeleted(): void
    {
        self::bootKernel();
        $all = $this->registry()->all();
        $osm = current(array_filter($all, static fn (DataProvider $p): bool => 'osm' === $p->getKey()));
        self::assertInstanceOf(DataProvider::class, $osm);

        $this->expectException(ProviderRuleException::class);
        $this->expectExceptionMessage('provider.error.system_undeletable');

        $this->registry()->delete($osm, $this->actor());
    }

    public function testARankOutsideTheBandIsRefused(): void
    {
        self::bootKernel();
        $provider = $this->seed('test-rank', 'cc0-1.0');

        $this->expectException(ProviderRuleException::class);
        $this->expectExceptionMessage('provider.error.rank_range');

        $this->registry()->update($provider, ['rank' => ProviderRegistry::RANK_MAX + 1], $this->actor());
    }

    public function testAHomepageMustBeAFullAddress(): void
    {
        self::bootKernel();
        $provider = $this->seed('test-url', 'cc0-1.0');

        $this->expectException(ProviderRuleException::class);
        $this->expectExceptionMessage('provider.error.bad_url');

        $this->registry()->update($provider, ['homepage' => 'geoportail.example'], $this->actor());
    }

    /**
     * A provider's rank decides what riders see, so a change to it is a
     * moderation act and leaves a trail. A save that moved nothing writes
     * nothing: a log of no-ops is a log nobody reads.
     */
    public function testEveryFieldThatMovedIsRecordedAndNothingElseIs(): void
    {
        self::bootKernel();
        $provider = $this->seed('test-history', 'cc0-1.0');
        $actor = $this->actor();

        $this->registry()->update($provider, ['name' => 'A new name', 'rank' => 42], $actor);
        $this->registry()->update($provider, ['name' => 'A new name'], $actor);

        $fields = array_column($this->registry()->history($provider), 'field');
        sort($fields);
        self::assertSame(['name', 'rank'], $fields);
    }

    private function seed(string $key, string $licenceCode): DataProvider
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $provider = new DataProvider($key, 'Test provider', 'Test provider, in full', 'https://example.test/', 'A licence', $licenceCode, 10);
        $em->persist($provider);
        $em->flush();

        return $provider;
    }

    private function registry(): ProviderRegistry
    {
        return static::getContainer()->get(ProviderRegistry::class);
    }

    private function actor(): User
    {
        return $this->user('provider-actor-'.bin2hex(random_bytes(4)).'@example.com', ['ROLE_CURATOR']);
    }

    /** @param list<string> $roles */
    private function user(string $email, array $roles = []): User
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
        $user->setRoles($roles);
        $user->setPassword($hasher->hashPassword($user, 'hunter2secure!'));
        if ([] !== $roles) {
            // A curator must be FULLY enrolled in 2FA or TwoFactorSetupEnforcer
            // redirects every page to /2fa/setup (account-and-auth.md).
            $user->setTotpSecret('JBSWY3DPEHPK3PXP');
            $user->setTwoFaEnabled(true);
        }
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function connection(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }
}
