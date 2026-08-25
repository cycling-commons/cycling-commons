<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Translation;

use App\Entity\User;
use App\Translation\Entity\TranslationEntry;
use App\Translation\Entity\TranslationProposal;
use App\Translation\TranslationProposalStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Rider /translate surface (translations.md §4).
 *
 * Test isolation: DAMA\DoctrineTestBundle wraps each test in a rolled-back transaction.
 */
final class TranslatePageTest extends WebTestCase
{
    /**
     * @param list<string> $roles
     */
    private function createUser(
        string $email,
        string $plain,
        array $roles = [],
    ): User {
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
        $user->setPassword($hasher->hashPassword($user, $plain));

        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function seedEntry(string $key, string $english): TranslationEntry
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $entry = new TranslationEntry($key, $english);
        $em->persist($entry);
        $em->flush();

        return $entry;
    }

    private function proposalCount(): int
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        return (int) $em->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(TranslationProposal::class, 'p')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function testAnonIsRedirectedFromTranslate(): void
    {
        $client = static::createClient();
        $client->request('GET', '/translate');

        self::assertResponseRedirects('/login', 302);
    }

    public function testEnglishLocaleShowsChooserWithoutTextarea(): void
    {
        $client = static::createClient();
        $user = $this->createUser('translate-en@example.com', 'hunter2secure!');
        $client->loginUser($user);

        $crawler = $client->request('GET', '/translate');
        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('/fr/translate', $html);
        self::assertStringContainsString('/nl/translate', $html);
        self::assertStringContainsString('/de/translate', $html);
        self::assertStringContainsString('/es/translate', $html);
        self::assertSame(0, $crawler->filter('textarea')->count());
    }

    public function testFrenchListShowsSeededKeyAndEnglish(): void
    {
        $client = static::createClient();
        $user = $this->createUser('translate-list@example.com', 'hunter2secure!');
        $this->seedEntry('home.cta_map', 'Explore the map');
        $client->loginUser($user);

        $client->request('GET', '/fr/translate');
        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('home.cta_map', $html);
        self::assertStringContainsString('Explore the map', $html);
    }

    public function testSearchFindsKeyByFragment(): void
    {
        $client = static::createClient();
        $user = $this->createUser('translate-search@example.com', 'hunter2secure!');
        $this->seedEntry('home.cta_map', 'Explore the map');
        $this->seedEntry('nav.map', 'Map');
        $client->loginUser($user);

        $client->request('GET', '/fr/translate?q=cta_map');
        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('home.cta_map', $html);
        self::assertStringNotContainsString('nav.map', $html);
    }

    public function testPostWithoutConsentCreatesNoProposal(): void
    {
        $client = static::createClient();
        $user = $this->createUser('translate-noconsent@example.com', 'hunter2secure!');
        $entry = $this->seedEntry('home.cta_map', 'Explore the map');
        $client->loginUser($user);

        $before = $this->proposalCount();
        $crawler = $client->request('GET', '/fr/translate/'.$entry->getId());
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[name="translation_proposal"]')->form([
            'translation_proposal[value]' => 'Explorer la carte',
            'translation_proposal[consent]' => false,
        ]);
        $client->submit($form);

        self::assertSame($before, $this->proposalCount());
    }

    public function testPostWithConsentCreatesPendingProposal(): void
    {
        $client = static::createClient();
        $user = $this->createUser('translate-consent@example.com', 'hunter2secure!');
        $entry = $this->seedEntry('home.cta_map', 'Explore the map');
        $client->loginUser($user);

        $before = $this->proposalCount();
        $crawler = $client->request('GET', '/fr/translate/'.$entry->getId());
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[name="translation_proposal"]')->form([
            'translation_proposal[value]' => 'Explorer la carte',
            'translation_proposal[consent]' => true,
        ]);
        $client->submit($form);

        self::assertResponseRedirects();
        self::assertSame($before + 1, $this->proposalCount());

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var TranslationProposal|null $proposal */
        $proposal = $em->createQueryBuilder()
            ->select('p')
            ->from(TranslationProposal::class, 'p')
            ->orderBy('p.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        self::assertNotNull($proposal);
        self::assertSame('Explorer la carte', $proposal->getProposedValue());
        self::assertSame('fr', $proposal->getLocale());
        self::assertSame(TranslationProposalStatus::Pending, $proposal->getStatus());
        self::assertSame((int) $user->getId(), $proposal->getSubmitterId());
    }

    public function testGermanPrefixWorks(): void
    {
        $client = static::createClient();
        $user = $this->createUser('translate-de@example.com', 'hunter2secure!');
        $this->seedEntry('home.cta_map', 'Explore the map');
        $client->loginUser($user);

        $client->request('GET', '/de/translate');
        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('home.cta_map', $html);
    }
}
