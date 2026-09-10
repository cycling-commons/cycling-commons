<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Translation;

use App\Controller\TranslateController;
use App\Entity\User;
use App\Pagination\PageSize;
use App\Routing\ActiveLocales;
use App\Translation\CatalogueBrowser;
use App\Translation\CatalogueCommit;
use App\Translation\CatalogueWriter;
use App\Translation\DeepL\DeepLAvailability;
use App\Translation\DeepL\DeepLClient;
use App\Translation\Entity\TranslationEntry;
use App\Translation\ProposalService;
use App\Translation\TranslationCaches;
use App\Translation\TranslationConsentService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The `/translate/{id}` form warns when `translation_entry.english_yaml`
 * (what the last `app:translations:sync` read) no longer matches what
 * `messages.en.yaml` holds right now (translations.md §3.1, §3.4 rule 7).
 * Nothing re-runs that sync on a hand edit or a git pull, so the projection
 * can silently drift from the file it is supposed to mirror; this suite
 * pins that the form says so, and says the right thing to the right
 * audience.
 *
 * Test isolation: DAMA\DoctrineTestBundle wraps each test in a rolled-back
 * transaction. CI's own `app:translations:sync` run seeds `translation_entry`
 * from the real catalogue before the suite starts (DAMA does not roll that
 * back), so a stable, real key already carries a matching `english_yaml` by
 * default. Drift has to be created deliberately: `applyGitEnglish()` is the
 * entity's own "git changed the wording" transition (translations.md §3.3);
 * calling it with a decoy string sets `english_yaml` to text the real file
 * does not hold, without touching messages.en.yaml or the translator.
 */
final class StaleSourceWarningTest extends WebTestCase
{
    use FindsOrCreatesTranslationEntry;

    private const string DECOY_ENGLISH = 'This wording no longer lives in the catalogue file.';

    private function createUser(string $email): User
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

    /**
     * A real, stable catalogue key (used elsewhere in this suite too) whose
     * git wording is 'Explore the map'. Fetches the row CI's sync already
     * created rather than assuming a fresh one, per FindsOrCreatesTranslationEntry.
     */
    private function driftedEntry(): TranslationEntry
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $entry = $this->findOrCreateEntry($em, 'home.cta_map', 'Explore the map');

        // Simulate the sync having read a wording git no longer holds: the
        // exact shape of "a colleague reworked the key after the last
        // sync ran" (task diagnosis). messages.en.yaml itself is untouched.
        $entry->applyGitEnglish(self::DECOY_ENGLISH);
        $em->flush();

        return $entry;
    }

    private function freshEntry(): TranslationEntry
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        return $this->findOrCreateEntry($em, 'home.cta_map', 'Explore the map');
    }

    /**
     * Builds a TranslateController with the given environment string, the
     * same construction DeepLDevToolTest uses for the same reason: this
     * controller reads `%kernel.environment%` through a constructor
     * argument, and the PHPUnit suite itself always runs under APP_ENV=test,
     * so "dev" can only be produced by building the controller by hand and
     * swapping it into the container.
     */
    private function installController(string $environment): void
    {
        $container = static::getContainer();
        $controller = new TranslateController(
            $container->get(CatalogueBrowser::class),
            $container->get(ActiveLocales::class),
            $container->get(ProposalService::class),
            $container->get(TranslationConsentService::class),
            $container->get(PageSize::class),
            $container->get(EntityManagerInterface::class),
            $container->get(TranslatorInterface::class),
            new CatalogueWriter(sys_get_temp_dir(), $environment, '0'),
            new CatalogueCommit(
                new CatalogueWriter(sys_get_temp_dir(), $environment, '0'),
                $container->get(EntityManagerInterface::class),
                $container->get(TranslationCaches::class),
            ),
            new DeepLClient(new MockHttpClient([]), ''),
            new DeepLAvailability($environment, ''),
            $container->get(TranslationCaches::class),
            $environment,
            new NullLogger(),
        );
        $controller->setContainer($container);
        $container->set(TranslateController::class, $controller);
    }

    public function testWarningAppearsWhenStoredEnglishYamlDiffersFromCatalogue(): void
    {
        $client = static::createClient();
        $entry = $this->driftedEntry();
        $client->loginUser($this->createUser('source-drift-warn@example.com'));

        $crawler = $client->request('GET', '/fr/translate/'.$entry->getId());
        self::assertResponseIsSuccessful();

        self::assertSame(1, $crawler->filter('.tr-warn')->count(), 'the drift warning must render');
        // messages.fr.yaml's non-dev wording (APP_ENV=test is not "dev").
        self::assertStringContainsString(
            'le catalogue a changé depuis la dernière projection',
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testNoWarningWhenStoredEnglishYamlMatchesCatalogue(): void
    {
        $client = static::createClient();
        $entry = $this->freshEntry();
        $client->loginUser($this->createUser('source-drift-clean@example.com'));

        $crawler = $client->request('GET', '/fr/translate/'.$entry->getId());
        self::assertResponseIsSuccessful();

        self::assertSame(0, $crawler->filter('.tr-warn')->count(), 'no drift, no warning');
        self::assertStringNotContainsString(
            'Le catalogue a changé depuis la dernière projection',
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testNonDevWarningNamesNoCommand(): void
    {
        // No installController() call: the suite's own APP_ENV=test
        // container is the "off dev" case the task asks for.
        $client = static::createClient();
        $entry = $this->driftedEntry();
        $client->loginUser($this->createUser('source-drift-nondev@example.com'));

        $client->request('GET', '/fr/translate/'.$entry->getId());
        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('app:translations:sync', $html, 'a rider must never be told to run a console command');
    }

    public function testDevWarningNamesTheSyncCommand(): void
    {
        $client = static::createClient();
        $entry = $this->driftedEntry();
        $client->loginUser($this->createUser('source-drift-dev@example.com'));
        $this->installController('dev');

        $client->request('GET', '/fr/translate/'.$entry->getId());
        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('app:translations:sync', $html, 'the developer audience must be told the fix');
    }
}
