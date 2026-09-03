<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Translation;

use App\Controller\TranslateController;
use App\Entity\User;
use App\Pagination\PageSize;
use App\Translation\CatalogueBrowser;
use App\Translation\CatalogueCommit;
use App\Translation\CatalogueWriter;
use App\Translation\DeepL\DeepLAvailability;
use App\Translation\DeepL\DeepLClient;
use App\Translation\Entity\TranslationEntry;
use App\Translation\Entity\TranslationOverlay;
use App\Translation\Entity\TranslationProposal;
use App\Translation\Exception\InvalidMarkupException;
use App\Translation\Exception\PlaceholderMismatchException;
use App\Translation\Exception\TranslationTooLongException;
use App\Translation\OverlayCatalogue;
use App\Translation\ProposalService;
use App\Translation\StaleIndex;
use App\Translation\TranslationCaches;
use App\Translation\TranslationConsentService;
use App\Translation\TranslationLimits;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The dev-only DeepL drafting tool wired into /translate/{id}
 * (translations.md §7.1, §7.2, §7.3): the two buttons, the endpoints they
 * call, and the submit path that writes straight into the catalogue on dev.
 *
 * The suite runs under APP_ENV=test throughout; nothing here boots a "dev"
 * kernel. "Dev" is produced by swapping the three services that read
 * %kernel.environment% (CatalogueWriter, DeepLClient, DeepLAvailability) and
 * the TranslateController built from them, following the same pattern
 * DeepLClientTest and CatalogueWriterTest already use for their own units:
 * MockHttpClient stands in for DeepL, never a real network call, and
 * CatalogueWriter is pointed at a scratch directory this test writes and
 * tears down itself, never `web/translations/`.
 *
 * `disableReboot()` is required wherever a test makes more than one
 * request: KernelBrowser reboots the kernel (a fresh container, discarding
 * every override below) before every request after the first one
 * (`web/vendor/symfony/framework-bundle/KernelBrowser.php`,
 * `doRequest()`), the same reason other suites in this repo call it
 * (see e.g. Auth/TwoFactorBruteForceTest.php).
 */
final class DeepLDevToolTest extends WebTestCase
{
    use FindsOrCreatesTranslationEntry;

    private const string TEST_KEY = 'translate.deepl_test_key';
    private const array LOCALES = ['fr', 'nl', 'de', 'es'];

    private string $scratchDir;

    protected function setUp(): void
    {
        $this->scratchDir = sys_get_temp_dir().'/deepl-dev-tool-test-'.bin2hex(random_bytes(8));
        mkdir($this->scratchDir);
        foreach (self::LOCALES as $locale) {
            file_put_contents($this->scratchPath($locale), $this->fixtureYaml($locale));
        }
    }

    protected function tearDown(): void
    {
        foreach (self::LOCALES as $locale) {
            @unlink($this->scratchPath($locale));
        }
        // Written only by the English dev-submit tests below, which is why
        // "en" is not in self::LOCALES: DeepL never targets English
        // (translations.md §7.2), so nothing else in this file writes it.
        @unlink($this->scratchPath('en'));
        @rmdir($this->scratchDir);

        // KernelTestCase::tearDown() shuts the kernel down; skipping it
        // leaves the "booted" flag set for the next test's createClient()
        // to trip over, especially with disableReboot() in play above.
        parent::tearDown();
    }

    private function scratchPath(string $locale): string
    {
        return $this->scratchDir.'/messages.'.$locale.'.yaml';
    }

    private function scratchBytes(string $locale): string
    {
        return (string) file_get_contents($this->scratchPath($locale));
    }

    private function fixtureYaml(string $locale): string
    {
        return "# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0\ntranslate:\n  deepl_test_key: 'Original {$locale}'\n";
    }

    private function createUser(string $email, array $roles = [], ?string $totpSecret = null): User
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
        if (null !== $totpSecret) {
            $user->setTotpSecret($totpSecret);
            $user->setTwoFaEnabled(true);
        }
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function seedEntry(string $key, string $english): TranslationEntry
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        return $this->findOrCreateEntry($em, $key, $english);
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

    /**
     * Builds a TranslateController with the given "dev" CatalogueWriter,
     * DeepLClient and DeepLAvailability and swaps it (plus DeepLAvailability
     * on its own: base.html.twig's script tag reads it through a separate
     * service, App\Twig\DeepLExtension, not through this controller) into
     * the test container. Every other constructor dependency is pulled from
     * the real container unchanged.
     *
     * setContainer() is required because this controller is being built by
     * hand rather than resolved by the DI container: the container
     * normally calls it automatically (AbstractController's `#[Required]
     * setContainer()`, wired by the `_instanceof` config the FrameworkBundle
     * applies to every AbstractController subclass) as part of building the
     * service. `new TranslateController(...)` bypasses that, so without
     * this line, $this->render()/addFlash()/isCsrfTokenValid()/createForm()
     * all fail with "has no container set".
     */
    private function installController(CatalogueWriter $writer, DeepLClient $deepl, DeepLAvailability $availability): void
    {
        $container = static::getContainer();
        $controller = new TranslateController(
            $container->get(CatalogueBrowser::class),
            $container->get(ProposalService::class),
            $container->get(TranslationConsentService::class),
            $container->get(PageSize::class),
            $container->get(EntityManagerInterface::class),
            $container->get(TranslatorInterface::class),
            $writer,
            new CatalogueCommit($writer, $container->get(EntityManagerInterface::class), $container->get(TranslationCaches::class)),
            $deepl,
            $availability,
            $container->get(TranslationCaches::class),
            'dev',
            new NullLogger(),
        );
        $controller->setContainer($container);

        $container->set(TranslateController::class, $controller);
        $container->set(DeepLAvailability::class, $availability);
    }

    /**
     * `$catalogueWrite` is the CC_CATALOGUE_WRITE opt-in (translations.md
     * §7.1), the signal that is independent of the environment. It defaults
     * to on here because most of this suite is about what the tool does once
     * a developer has switched it on; the tests that matter for the licence
     * boundary switch it off explicitly.
     *
     * @param list<MockResponse> $responses
     */
    private function useDevTools(array $responses = [], bool $deeplKeyPresent = true, string $catalogueWrite = '1'): void
    {
        $key = $deeplKeyPresent ? 'test-key' : '';
        $this->installController(
            new CatalogueWriter($this->scratchDir, 'dev', $catalogueWrite),
            new DeepLClient(new MockHttpClient($responses), $key),
            new DeepLAvailability('dev', $key),
        );
    }

    // --- The buttons (translations.md §7.2) ------------------------------

    public function testButtonsRenderOnDevWithTheFeatureOn(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entry = $this->seedEntry(self::TEST_KEY, 'Original text');
        $client->loginUser($this->createUser('deepl-buttons-on@example.com'));
        $this->useDevTools();

        $crawler = $client->request('GET', '/fr/translate/'.$entry->getId());
        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('.tr-deepl-draft')->count());
        // One tick box per rider locale: the panel drafts what is ticked.
        self::assertSame(\count(self::LOCALES), $crawler->filter('[data-deepl-locale]')->count());
    }

    public function testButtonsDoNotRenderWithoutAConfiguredKey(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entry = $this->seedEntry(self::TEST_KEY, 'Original text');
        $client->loginUser($this->createUser('deepl-buttons-nokey@example.com'));
        $this->useDevTools(deeplKeyPresent: false);

        $crawler = $client->request('GET', '/fr/translate/'.$entry->getId());
        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('.tr-deepl-draft')->count());
        self::assertSame(0, $crawler->filter('[data-deepl-locale]')->count());
    }

    public function testButtonsDoNotRenderOffDev(): void
    {
        // No useDevTools() call: the suite's own APP_ENV=test container,
        // with whatever DEEPL_API_KEY (empty, per web/.env) is configured.
        $client = static::createClient();
        $entry = $this->seedEntry(self::TEST_KEY, 'Original text');
        $client->loginUser($this->createUser('deepl-buttons-offdev@example.com'));

        $crawler = $client->request('GET', '/fr/translate/'.$entry->getId());
        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('.tr-deepl-draft')->count());
        self::assertSame(0, $crawler->filter('[data-deepl-locale]')->count());
    }

    public function testButtonsNeverRenderOnTheEnglishPage(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entry = $this->seedEntry(self::TEST_KEY, 'Original text');
        // English is proposable to curators only (translations.md §4.2); a
        // rider would just be redirected to the chooser and never see a
        // form at all, so this uses a curator to reach the English form on
        // its own terms and confirm the buttons still do not render there.
        $curator = $this->createUser('deepl-buttons-en@example.com', ['ROLE_CURATOR'], 'JBSWY3DPEHPK3PXP');
        $client->loginUser($curator);
        $this->useDevTools();

        $crawler = $client->request('GET', '/translate/'.$entry->getId());
        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('.tr-deepl-draft')->count());
        self::assertSame(0, $crawler->filter('[data-deepl-locale]')->count());
    }

    // --- Draft this locale (translations.md §7.2) -------------------------

    public function testDraftThisLocaleReturnsADraftWithoutWritingToAnyFile(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entry = $this->seedEntry(self::TEST_KEY, 'Original text');
        $client->loginUser($this->createUser('deepl-draft-one@example.com'));
        $this->useDevTools([
            new MockResponse(json_encode(['translations' => [['text' => 'Texte drafte']]], \JSON_THROW_ON_ERROR)),
        ]);

        $before = $this->scratchBytes('fr');
        $before .= $this->scratchBytes('nl').$this->scratchBytes('de').$this->scratchBytes('es');

        $crawler = $client->request('GET', '/fr/translate/'.$entry->getId());
        $csrf = (string) $crawler->filter('[data-deepl]')->attr('data-csrf');

        $client->request('POST', '/fr/translate/'.$entry->getId().'/deepl-draft', ['_csrf_token' => $csrf]);
        self::assertResponseIsSuccessful();
        /** @var array{drafts?: array<string, string>} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        // No tick box posted: the endpoint drafts the locale being edited.
        self::assertSame(['fr' => 'Texte drafte'], $data['drafts'] ?? null);

        $after = $this->scratchBytes('fr').$this->scratchBytes('nl').$this->scratchBytes('de').$this->scratchBytes('es');
        self::assertSame($before, $after, 'draft-this-locale must write nothing');
    }

    public function testDraftThisLocaleNeverRendersOrAnswersForEnglish(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entry = $this->seedEntry(self::TEST_KEY, 'Original text');
        $client->loginUser($this->createUser('deepl-draft-en@example.com'));
        $this->useDevTools();

        // The English page never renders this token (the button that would
        // need it does not render there either), so a valid one is fetched
        // from the French page first, in the same session: the CSRF check
        // in the controller runs before the locale check, and a bad token
        // would 403 there regardless of locale, telling this test nothing
        // about the guard it actually means to exercise.
        $crawler = $client->request('GET', '/fr/translate/'.$entry->getId());
        $csrf = (string) $crawler->filter('[data-deepl]')->attr('data-csrf');

        $client->request('POST', '/en/translate/'.$entry->getId().'/deepl-draft', ['_csrf_token' => $csrf]);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * The panel's language tick boxes decide what DeepL is asked for, and
     * the answer only ever fills fields: the write is the developer's own
     * separate act (translations.md §7.2).
     */
    public function testDraftDraftsEveryTickedLocaleAndWritesNothing(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entry = $this->seedEntry(self::TEST_KEY, 'Original text');
        $client->loginUser($this->createUser('deepl-draft-ticked@example.com'));
        $this->installController(
            new CatalogueWriter($this->scratchDir, 'dev', '1'),
            new DeepLClient($this->echoingDeepL(['fr' => 'Texte fr', 'nl' => 'Tekst nl', 'de' => 'Text de', 'es' => 'Texto es']), 'test-key'),
            new DeepLAvailability('dev', 'test-key'),
        );

        $before = '';
        foreach (self::LOCALES as $locale) {
            $before .= $this->scratchBytes($locale);
        }

        $crawler = $client->request('GET', '/fr/translate/'.$entry->getId());
        $csrf = (string) $crawler->filter('[data-deepl]')->attr('data-csrf');

        $client->request('POST', '/fr/translate/'.$entry->getId().'/deepl-draft', [
            '_csrf_token' => $csrf,
            'locales' => ['fr', 'nl'],
        ]);
        self::assertResponseIsSuccessful();
        /** @var array{drafts?: array<string, string>} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['fr' => 'Texte fr', 'nl' => 'Tekst nl'], $data['drafts'] ?? null);

        $after = '';
        foreach (self::LOCALES as $locale) {
            $after .= $this->scratchBytes($locale);
        }
        self::assertSame($before, $after, 'drafting must write nothing');
    }

    /**
     * @param array<string, string> $texts
     */
    private function echoingDeepL(array $texts): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options) use ($texts): MockResponse {
            /** @var array{target_lang: string} $body */
            $body = json_decode((string) $options['body'], true, flags: \JSON_THROW_ON_ERROR);
            $target = strtolower($body['target_lang']);

            return new MockResponse(json_encode(['translations' => [['text' => $texts[$target]]]], \JSON_THROW_ON_ERROR));
        });
    }

    // --- Writing the ticked locales (translations.md §7.3) ---------------

    /**
     * The write tick boxes are one group directly above the write button,
     * not one box trailing each field: the developer decides what to write
     * as a single act, at the point of writing.
     */
    public function testTheWriteTickBoxesAreOneGroupAboveTheButton(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entry = $this->seedEntry(self::TEST_KEY, 'Original text');
        $client->loginUser($this->createUser('deepl-tickbox-layout@example.com'));
        $this->useDevTools();

        $crawler = $client->request('GET', '/fr/translate/'.$entry->getId());
        self::assertResponseIsSuccessful();

        self::assertSame(
            \count(self::LOCALES),
            $crawler->filter('.tr-save-pick input[type="checkbox"]')->count(),
            'every locale has its write tick box in the group',
        );
        self::assertSame(
            0,
            $crawler->filter('[data-locale-row] input[type="checkbox"]')->count(),
            'no write tick box may trail a text field',
        );
        // Each text field still carries the value it started with, so the
        // browser can tell a changed field from an untouched one.
        self::assertSame(
            \count(self::LOCALES),
            $crawler->filter('[data-locale-row] textarea[data-initial]')->count(),
        );
    }

    /**
     * A refusal on one ticked locale is a refusal for the whole submit: the
     * values are all checked before any of them is written.
     */
    public function testARefusedTickedLocaleStopsTheWholeSubmit(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entry = $this->seedEntry(self::TEST_KEY, 'Original text');
        $client->loginUser($this->createUser('deepl-ticked-refused@example.com'));
        $this->useDevTools();

        $crawler = $client->request('GET', '/fr/translate/'.$entry->getId());
        $form = $crawler->filter('form[name="translation_proposal"]')->form([
            'translation_proposal[fr]' => 'Texte correct',
            'translation_proposal[nl]' => '',
            'translation_proposal[save_nl]' => true,
        ]);
        $client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Texte correct', $this->scratchBytes('fr'), 'no locale may be written when another ticked one is refused');
        self::assertSelectorExists('[role="alert"]');
    }

    /**
     * Untick everything and the form says so, rather than redirecting with a
     * success banner for a write that never happened.
     */
    public function testSubmittingWithNoLocaleTickedWritesNothingAndSaysSo(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entry = $this->seedEntry(self::TEST_KEY, 'Original text');
        $client->loginUser($this->createUser('deepl-ticked-none@example.com'));
        $this->useDevTools();

        $crawler = $client->request('GET', '/fr/translate/'.$entry->getId());
        $form = $crawler->filter('form[name="translation_proposal"]')->form([
            'translation_proposal[fr]' => 'Texte jamais écrit',
        ]);
        $form['translation_proposal[save_fr]']->untick();
        $client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Texte jamais écrit', $this->scratchBytes('fr'));
        self::assertSelectorExists('[role="alert"]');
    }

    // --- The dev submit path (translations.md §7.3) -----------------------

    /**
     * The dev form carries every rider locale, and its per-locale tick box
     * is what decides which of them reaches a file (translations.md §7.3).
     */
    public function testADevSubmitWritesOnlyTheTickedLocales(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entry = $this->seedEntry(self::TEST_KEY, 'Original text');
        $client->loginUser($this->createUser('deepl-ticked-write@example.com'));
        $this->useDevTools();

        $crawler = $client->request('GET', '/fr/translate/'.$entry->getId());
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[name="translation_proposal"]')->form([
            'translation_proposal[fr]' => 'Texte coché',
            'translation_proposal[nl]' => 'Niet aangevinkte tekst',
        ]);
        $client->submit($form);

        self::assertResponseRedirects();
        self::assertStringContainsString("'Texte coché'", $this->scratchBytes('fr'));
        self::assertStringNotContainsString('Niet aangevinkte tekst', $this->scratchBytes('nl'));
    }

    /**
     * The point of the whole exercise: the file is the base, so what it now
     * says is what the site says, with no row left on top of it.
     */
    public function testADevSubmitDropsTheOverlayOfEveryTickedLocaleOnly(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entry = $this->seedEntry(self::TEST_KEY, 'Original text');
        $client->loginUser($this->createUser('deepl-ticked-overlay@example.com'));
        $this->useDevTools();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new TranslationOverlay($entry, 'fr', 'Texte overlay', null, null, 1));
        $em->persist(new TranslationOverlay($entry, 'nl', 'Overlay-tekst', null, null, 1));
        $em->flush();

        $crawler = $client->request('GET', '/fr/translate/'.$entry->getId());
        $form = $crawler->filter('form[name="translation_proposal"]')->form([
            'translation_proposal[fr]' => 'Texte de base',
        ]);
        $client->submit($form);
        self::assertResponseRedirects();

        $em->clear();
        $again = $em->getRepository(TranslationEntry::class)->findOneBy(['messageKey' => self::TEST_KEY]);
        self::assertNotNull($again);
        $overlays = $em->getRepository(TranslationOverlay::class);
        self::assertNull($overlays->findOneBy(['entry' => $again, 'locale' => 'fr']));
        self::assertNotNull($overlays->findOneBy(['entry' => $again, 'locale' => 'nl']));
    }

    public function testADevSubmitWritesTheCatalogueAndCreatesNoProposalRow(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entry = $this->seedEntry(self::TEST_KEY, 'Original text');
        $client->loginUser($this->createUser('deepl-devsubmit@example.com'));
        $this->useDevTools();

        $before = $this->proposalCount();
        $crawler = $client->request('GET', '/fr/translate/'.$entry->getId());
        self::assertResponseIsSuccessful();

        // No consent field exists to fill in on dev: the form only carries
        // "value" (see testTheConsentBlockIsAbsentOnDev below for the
        // assertion that covers this directly).
        $form = $crawler->filter('form[name="translation_proposal"]')->form([
            'translation_proposal[fr]' => 'Hand typed on dev',
        ]);
        $client->submit($form);

        self::assertResponseRedirects();
        self::assertSame($before, $this->proposalCount(), 'a dev submit must create no proposal row');
        self::assertStringContainsString("'Hand typed on dev'", $this->scratchBytes('fr'));

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        // The multi-locale dev form names what it wrote, rather than the
        // single-field path's "written straight into the catalogue file".
        self::assertSelectorTextContains('[role="alert"]', 'Écrit : fr.');
    }

    public function testADevSubmitThroughTheEmbedDrawerAnswersItsOwnWrittenMessage(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entry = $this->seedEntry(self::TEST_KEY, 'Original text');
        $client->loginUser($this->createUser('deepl-devsubmit-embed@example.com'));
        $this->useDevTools();

        $crawler = $client->request('GET', '/fr/translate/'.$entry->getId().'?embed=1');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form.tr-form')->form([
            'translation_proposal[fr]' => 'Drawer dev submit',
        ]);
        $client->submit($form);

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('tr-embed-sent', $html);
        // Never the curator-review wording (translations.md §7.3): nothing
        // here goes to a curator.
        self::assertStringNotContainsString('un curateur la relira', $html);
        self::assertStringContainsString("'Drawer dev submit'", $this->scratchBytes('fr'));
    }

    public function testOffDevTheSubmitPathIsUnchangedAndStillCreatesAProposal(): void
    {
        // No useDevTools(): the plain APP_ENV=test container, exactly the
        // path TranslatePageTest's testPostWithConsentCreatesPendingProposal
        // already covers. Repeated here, narrowly, so this file's own dev
        // vs off-dev pairing does not depend on reading two files together.
        $client = static::createClient();
        $entry = $this->seedEntry(self::TEST_KEY, 'Original text');
        $client->loginUser($this->createUser('deepl-offdev-submit@example.com'));

        $before = $this->proposalCount();
        $crawler = $client->request('GET', '/fr/translate/'.$entry->getId());
        $form = $crawler->filter('form[name="translation_proposal"]')->form([
            'translation_proposal[value]' => 'Off dev, still a proposal',
            'translation_proposal[consent]' => true,
        ]);
        $client->submit($form);

        self::assertResponseRedirects();
        self::assertSame($before + 1, $this->proposalCount());
        // Untouched: the off-dev path never reaches CatalogueWriter, and
        // this scratch directory is not web/translations/ anyway.
        self::assertStringContainsString("'Original fr'", $this->scratchBytes('fr'));
    }

    // --- The dev submit path also covers English (translations.md §7.3) --

    private const string ENGLISH_TEST_KEY = 'translate.dev_english_test_key';

    /**
     * Builds a TranslateController whose CatalogueBrowser reads English
     * through a translator that parses this test's own scratch
     * `messages.en.yaml` fresh on every call, instead of the container's
     * real translator (wired to `web/translations/`, per
     * `config/packages/translation.yaml`'s `default_path`). On real dev,
     * CatalogueWriter's directory and the translator's resource directory
     * are the same path, so a write is immediately visible to the next
     * read; this stand-in reproduces exactly that alignment against the
     * scratch directory instead, without writing a real catalogue file.
     */
    private function installEnglishAwareController(): void
    {
        $container = static::getContainer();
        $scratchDir = $this->scratchDir;
        $translator = new class($scratchDir) implements TranslatorInterface {
            public function __construct(private readonly string $dir)
            {
            }

            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                $path = $this->dir.'/messages.'.($locale ?? 'en').'.yaml';
                if (!is_file($path)) {
                    return $id;
                }
                $cursor = Yaml::parseFile($path);
                foreach (explode('.', $id) as $segment) {
                    if (!\is_array($cursor) || !\array_key_exists($segment, $cursor)) {
                        return $id;
                    }
                    $cursor = $cursor[$segment];
                }

                return \is_string($cursor) ? strtr($cursor, $parameters) : $id;
            }

            public function getLocale(): string
            {
                return 'en';
            }
        };

        $browser = new CatalogueBrowser(
            $container->get(EntityManagerInterface::class),
            $container->get(Connection::class),
            $container->get(OverlayCatalogue::class),
            $translator,
            $container->get(Security::class),
        );

        $controller = new TranslateController(
            $browser,
            $container->get(ProposalService::class),
            $container->get(TranslationConsentService::class),
            $container->get(PageSize::class),
            $container->get(EntityManagerInterface::class),
            $container->get(TranslatorInterface::class),
            new CatalogueWriter($this->scratchDir, 'dev', '1'),
            new CatalogueCommit(
                new CatalogueWriter($this->scratchDir, 'dev', '1'),
                static::getContainer()->get(EntityManagerInterface::class),
                static::getContainer()->get(TranslationCaches::class),
            ),
            new DeepLClient(new MockHttpClient(), 'test-key'),
            new DeepLAvailability('dev', 'test-key'),
            $container->get(TranslationCaches::class),
            'dev',
            new NullLogger(),
        );
        $controller->setContainer($container);
        $container->set(TranslateController::class, $controller);
    }

    /**
     * Seeds the entry, an existing overlay in every rider locale (made
     * against the entry's current English version, exactly what a real
     * approved translation looks like), and a scratch `messages.en.yaml`
     * carrying the same original wording, so a subsequent English dev
     * submit has both a file to rewrite and translations that can go stale.
     */
    private function seedEnglishDevSubmitFixture(string $originalEnglish): TranslationEntry
    {
        $entry = $this->seedEntry(self::ENGLISH_TEST_KEY, $originalEnglish);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        foreach (self::LOCALES as $locale) {
            $em->persist(new TranslationOverlay($entry, $locale, 'Translated '.$locale, null, null, $entry->getEnglishVersion()));
        }
        $em->flush();

        file_put_contents(
            $this->scratchPath('en'),
            "# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0\ntranslate:\n  dev_english_test_key: '{$originalEnglish}'\n",
        );

        return $entry;
    }

    public function testADevSubmitOfEnglishWritesTheCatalogueAndCreatesNoProposalRow(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entry = $this->seedEnglishDevSubmitFixture('Original text');
        $client->loginUser($this->createUser('deepl-devsubmit-english@example.com', ['ROLE_CURATOR'], 'JBSWY3DPEHPK3PXP'));
        $this->installEnglishAwareController();

        $before = $this->proposalCount();
        $crawler = $client->request('GET', '/translate/'.$entry->getId());
        self::assertResponseIsSuccessful();
        // English picks up the dev-submit wiring for free: no consent
        // block, and the button names the act it performs.
        self::assertSame(0, $crawler->filter('input[name="translation_proposal[consent]"]')->count());
        self::assertSelectorTextContains('.tr-actions button[type="submit"]', 'Write to catalogue file');

        $form = $crawler->filter('form[name="translation_proposal"]')->form([
            'translation_proposal[value]' => 'Updated straight from dev',
        ]);
        $client->submit($form);

        self::assertResponseRedirects();
        self::assertSame($before, $this->proposalCount(), 'a dev English submit must create no proposal row');
        self::assertStringContainsString("dev_english_test_key: 'Updated straight from dev'", $this->scratchBytes('en'));

        /** @var TranslationEntry $refreshed */
        $refreshed = static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(TranslationEntry::class)
            ->findOneBy(['messageKey' => self::ENGLISH_TEST_KEY]);
        self::assertSame('Updated straight from dev', $refreshed->getEnglish());
        self::assertSame('Updated straight from dev', $refreshed->getEnglishYaml(), 'the stored YAML English must move with the file just written');
        self::assertSame(2, $refreshed->getEnglishVersion());
        self::assertSame(2, $refreshed->getYamlEnglishVersion(), 'yaml_english_version must move with english_version, the same way a git-side change does');
    }

    public function testADevSubmitOfEnglishClearsTheDriftWarningAndMakesTheFourTranslationsStale(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entry = $this->seedEnglishDevSubmitFixture('Original text');
        $entryId = (int) $entry->getId();
        $client->loginUser($this->createUser('deepl-devsubmit-english-stale@example.com', ['ROLE_CURATOR'], 'JBSWY3DPEHPK3PXP'));
        $this->installEnglishAwareController();

        /** @var StaleIndex $stale */
        $stale = static::getContainer()->get(StaleIndex::class);
        foreach (self::LOCALES as $locale) {
            self::assertFalse($stale->isStale($entryId, $locale), $locale.' must not be stale before the English edit');
        }

        $crawler = $client->request('GET', '/translate/'.$entry->getId());
        $form = $crawler->filter('form[name="translation_proposal"]')->form([
            'translation_proposal[value]' => 'Updated straight from dev',
        ]);
        $client->submit($form);
        self::assertResponseRedirects();

        // The entry just written reads back clean: the stored YAML English
        // now matches the file, so the drift warning does not fire on it.
        $crawler = $client->request('GET', '/translate/'.$entry->getId());
        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('.tr-warn')->count(), 'the drift warning must not fire right after the write that fixed it');

        // Bumping english_version is correct and wanted (translations.md
        // §3.3): the English moved, so every existing translation of this
        // key is now behind it.
        foreach (self::LOCALES as $locale) {
            self::assertTrue($stale->isStale($entryId, $locale), $locale.' must be stale after the English dev submit');
        }
    }

    // --- Item 1 review hardening: the dev submit path runs the same two
    // checks ProposalService::submit() runs, before CatalogueWriter::write()
    // (translations.md §7.3). ----------------------------------------------

    public function testADevSubmitWithBrokenMarkupIsRefusedAndWritesNothing(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entry = $this->seedEntry(self::TEST_KEY, 'Original text');
        $client->loginUser($this->createUser('deepl-devsubmit-markup@example.com'));
        $this->useDevTools();

        $before = $this->scratchBytes('fr');
        $crawler = $client->request('GET', '/fr/translate/'.$entry->getId());
        // An unclosed <b>: exactly the case TranslationMarkup::check() exists
        // to catch, the same way it catches it for a rider's proposal.
        $form = $crawler->filter('form[name="translation_proposal"]')->form([
            'translation_proposal[fr]' => '<b>Broken tag, never closed',
        ]);
        $client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSame($before, $this->scratchBytes('fr'), 'broken markup must write nothing');
        self::assertSelectorTextContains(
            '[role="alert"]',
            'La balise <b> n\'est pas fermée, ou ferme quelque chose qu\'elle n\'a pas ouvert.',
        );
    }

    public function testADevSubmitOverTheLengthCapIsRefusedAndWritesNothing(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entry = $this->seedEntry(self::TEST_KEY, 'Original text');
        $client->loginUser($this->createUser('deepl-devsubmit-toolong@example.com'));
        $this->useDevTools();

        $before = $this->scratchBytes('fr');
        $crawler = $client->request('GET', '/fr/translate/'.$entry->getId());
        $form = $crawler->filter('form[name="translation_proposal"]')->form([
            'translation_proposal[fr]' => str_repeat('a', TranslationLimits::PROPOSED_VALUE_MAX + 1),
        ]);
        $client->submit($form);

        // AbstractController::render() sets 422 itself whenever a submitted
        // form in the view context is invalid (framework-bundle
        // AbstractController.php), which is exactly what happens here: the
        // form's own Length constraint on "value" (TranslationProposalType)
        // already refuses this before the isDevSubmit branch is even
        // reached, exactly as it does for a rider's submission of the same
        // form. That is the field-level error rendered here; it is not a
        // CSS selector this test pins down, so the assertion below reads the
        // raw response body instead. See
        // testAssertDevSubmitAcceptableThrowsOnBrokenMarkupAndOnLength below
        // for the test that exercises the length check this task actually
        // added, independent of that pre-existing form guard.
        self::assertResponseStatusCodeSame(422);
        self::assertSame($before, $this->scratchBytes('fr'), 'an over-cap dev submit must write nothing');
        self::assertStringContainsString('trop long', (string) $client->getResponse()->getContent());
    }

    /**
     * `TranslateController::assertDevSubmitAcceptable()` is what the dev
     * submit branch calls before `CatalogueWriter::write()`. It is exercised
     * directly here, by reflection, rather than only through the HTTP form
     * above: `TranslationProposalType`'s own `Length` constraint already
     * refuses an over-cap value for every submission of this form, dev
     * included, so an HTTP-only test of the length cap would still pass
     * even if this method's length check were deleted. Calling it directly
     * proves the method itself, not the form around it, is what item 1
     * asked for.
     */
    public function testAssertDevSubmitAcceptableThrowsOnBrokenMarkupAndOnLength(): void
    {
        $entry = $this->seedEntry(self::TEST_KEY, 'Original text');
        $controller = new TranslateController(
            static::getContainer()->get(CatalogueBrowser::class),
            static::getContainer()->get(ProposalService::class),
            static::getContainer()->get(TranslationConsentService::class),
            static::getContainer()->get(PageSize::class),
            static::getContainer()->get(EntityManagerInterface::class),
            static::getContainer()->get(TranslatorInterface::class),
            new CatalogueWriter($this->scratchDir, 'dev', '1'),
            new CatalogueCommit(
                new CatalogueWriter($this->scratchDir, 'dev', '1'),
                static::getContainer()->get(EntityManagerInterface::class),
                static::getContainer()->get(TranslationCaches::class),
            ),
            new DeepLClient(new MockHttpClient(), 'test-key'),
            new DeepLAvailability('dev', 'test-key'),
            static::getContainer()->get(TranslationCaches::class),
            'dev',
            new NullLogger(),
        );
        $method = new \ReflectionMethod(TranslateController::class, 'assertDevSubmitAcceptable');

        try {
            $method->invoke($controller, '<b>Broken tag, never closed', $entry);
            self::fail('Expected InvalidMarkupException');
        } catch (InvalidMarkupException) {
        }

        try {
            $method->invoke($controller, str_repeat('a', TranslationLimits::PROPOSED_VALUE_MAX + 1), $entry);
            self::fail('Expected TranslationTooLongException');
        } catch (TranslationTooLongException) {
        }

        // A placeholder DeepL translated rather than carried across. The
        // English here has none, so this uses an entry that does.
        $withPlaceholder = $this->seedEntry('translate.deepl_placeholder_key', 'Changed on %date% by %name%.');
        try {
            $method->invoke($controller, 'Gewijzigd op %datum% door %name%.', $withPlaceholder);
            self::fail('Expected PlaceholderMismatchException');
        } catch (PlaceholderMismatchException $e) {
            self::assertStringContainsString('%date%', $e->getMessage());
        }

        // Reordering placeholders is normal in another language and must
        // not be refused.
        $method->invoke($controller, 'Door %name% gewijzigd op %date%.', $withPlaceholder);

        // A value within bounds and well-formed raises neither.
        $method->invoke($controller, 'Well formed and short', $entry);
        $this->addToAssertionCount(1);
    }

    // --- The CC_CATALOGUE_WRITE opt-in (translations.md §7.1) -------------
    //
    // C1: the kernel environment cannot carry the licence boundary alone.
    // web/.env commits APP_ENV=dev and no deployed environment file in this
    // repository overrides it, so a box that lost its server-side override
    // is "dev" to the application. These tests pin what happens then: the
    // ORDINARY rider path, unchanged, because the second signal is off.

    public function testWithoutTheOptInADevSubmitStillGoesThroughTheProposalFlow(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entry = $this->seedEntry(self::TEST_KEY, 'Original text');
        $client->loginUser($this->createUser('deepl-noptin-submit@example.com'));
        // "dev" in every other respect, DeepL key included. Only the
        // catalogue-write opt-in is absent, exactly as it is on a release.
        $this->useDevTools(catalogueWrite: '');

        $before = $this->proposalCount();
        $crawler = $client->request('GET', '/fr/translate/'.$entry->getId());
        self::assertResponseIsSuccessful();

        // The consent tick is BACK, because this is a rider proposal again.
        // Without it the submit is refused, which is itself the point: the
        // CC BY-SA grant is asked for and recorded on this path.
        $form = $crawler->filter('form[name="translation_proposal"]')->form([
            'translation_proposal[value]' => 'Typed with no opt-in set',
            'translation_proposal[consent]' => true,
        ]);
        $client->submit($form);

        self::assertResponseRedirects();
        self::assertSame($before + 1, $this->proposalCount(), 'without the opt-in this must be a proposal row, not a file write');
        self::assertStringNotContainsString('Typed with no opt-in set', $this->scratchBytes('fr'), 'without the opt-in nothing may reach a catalogue file');
        self::assertStringContainsString("'Original fr'", $this->scratchBytes('fr'));
    }

    /**
     * Without the write opt-in the panel still drafts, because drafting
     * writes nothing; what it does not get is the per-locale machinery,
     * which exists only to write (translations.md §7.1).
     */
    public function testWithoutTheOptInTheDraftPanelHasNoPerLocaleControls(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entry = $this->seedEntry(self::TEST_KEY, 'Original text');
        $client->loginUser($this->createUser('deepl-noptin-draftall@example.com'));
        $this->useDevTools(catalogueWrite: '');

        $crawler = $client->request('GET', '/fr/translate/'.$entry->getId());
        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('.tr-deepl-draft')->count());
        self::assertSame(0, $crawler->filter('[data-deepl-locale]')->count(), 'no language tick boxes without the write opt-in');
        self::assertSame(0, $crawler->filter('[data-locale-row]')->count(), 'no per-locale fields without the write opt-in');
        self::assertStringContainsString("'Original fr'", $this->scratchBytes('fr'));
    }

    // --- Protected keys on the write paths (translations.md §4) -----------

    public function testAProtectedConsentContractIsRefusedForEveryTickedLocale(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        // The consent contract, whose exact wording is hashed into the
        // consent ledger under a VERSION. A paraphrase here would leave
        // every stored consent record covering words its rider never saw.
        $entry = $this->seedEntry('translate.consent.contract', 'I agree to license this translation under CC BY-SA 4.0.');
        $client->loginUser($this->createUser('deepl-protected-all@example.com'));

        foreach (self::LOCALES as $locale) {
            file_put_contents(
                $this->scratchPath($locale),
                "# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0\ntranslate:\n  consent:\n    contract: 'Original {$locale}'\n",
            );
        }
        $this->useDevTools();

        $crawler = $client->request('GET', '/fr/translate/'.$entry->getId());
        $form = $crawler->filter('form[name="translation_proposal"]')->form();
        foreach (self::LOCALES as $locale) {
            $form['translation_proposal['.$locale.']'] = 'A paraphrase of a binding licence sentence';
            $form['translation_proposal[save_'.$locale.']']->tick();
        }
        $client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role="alert"]', 'consent contract');
        foreach (self::LOCALES as $locale) {
            self::assertStringContainsString("'Original {$locale}'", $this->scratchBytes($locale));
        }
    }

    // --- The write path runs the acceptance check per locale (I4) ---------

    /**
     * What DeepL really does to a marked-up, placeholder-carrying string:
     * an unclosed tag in one language, a translated placeholder in another.
     * The developer can leave either in a field by accident, so the write
     * refuses it and no language is written at all.
     */
    public function testATickedLocaleWithBrokenMarkupIsRefusedAndWritesNothing(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entry = $this->seedEntry(self::TEST_KEY, 'Read the <b>rules</b> for %name%.');
        $client->loginUser($this->createUser('deepl-ticked-markup@example.com'));
        $this->useDevTools();

        $crawler = $client->request('GET', '/fr/translate/'.$entry->getId());
        $form = $crawler->filter('form[name="translation_proposal"]')->form([
            'translation_proposal[fr]' => 'Lisez les <b>règles</b> pour %name%.',
            'translation_proposal[de]' => 'Lies die <b>Regeln für %name%.',
        ]);
        $form['translation_proposal[save_de]']->tick();
        $client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[role="alert"]');
        self::assertStringContainsString("'Original de'", $this->scratchBytes('de'));
        self::assertStringContainsString("'Original fr'", $this->scratchBytes('fr'));
    }

    public function testATickedLocaleWithATranslatedPlaceholderIsRefused(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entry = $this->seedEntry(self::TEST_KEY, 'Read the rules for %name%.');
        $client->loginUser($this->createUser('deepl-ticked-placeholder@example.com'));
        $this->useDevTools();

        $crawler = $client->request('GET', '/fr/translate/'.$entry->getId());
        $form = $crawler->filter('form[name="translation_proposal"]')->form([
            'translation_proposal[es]' => 'Lee las reglas para %nombre%.',
        ]);
        $form['translation_proposal[save_es]']->tick();
        $client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[role="alert"]');
        self::assertStringContainsString("'Original es'", $this->scratchBytes('es'));
    }

    public function testATickedLocaleOverTheLengthCapIsRefusedAndWritesNothing(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entry = $this->seedEntry(self::TEST_KEY, 'Short English.');
        $client->loginUser($this->createUser('deepl-ticked-long@example.com'));
        $this->useDevTools();

        $long = str_repeat('a', TranslationLimits::PROPOSED_VALUE_MAX + 1);
        $crawler = $client->request('GET', '/fr/translate/'.$entry->getId());
        $form = $crawler->filter('form[name="translation_proposal"]')->form([
            'translation_proposal[de]' => $long,
        ]);
        $form['translation_proposal[save_de]']->tick();
        $client->submit($form);

        // The field's own Length constraint refuses it before the write
        // path is reached, which Symfony reports as 422.
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString("'Original de'", $this->scratchBytes('de'));
    }

    // --- The refusal reason reaches the developer (I5) --------------------

    public function testAFailedDevSubmitFlashesTheActualReason(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        // A key no catalogue file in the scratch directory carries, so the
        // write refuses with a message naming the key and the file.
        $entry = $this->seedEntry('translate.absent_from_the_files', 'Original text');
        $client->loginUser($this->createUser('deepl-reason@example.com'));
        $this->useDevTools();

        $crawler = $client->request('GET', '/fr/translate/'.$entry->getId());
        $form = $crawler->filter('form[name="translation_proposal"]')->form([
            'translation_proposal[fr]' => 'Anything at all',
        ]);
        $client->submit($form);

        self::assertResponseIsSuccessful();
        $alert = (string) $client->getResponse()->getContent();
        // The old copy told the developer to read terminal output that
        // nothing was writing, and threw the composed reason away.
        self::assertStringNotContainsString('la sortie du terminal', $alert);
        self::assertStringContainsString('translate.absent_from_the_files', $alert);
    }

    // --- The button names the act it performs (I6) ------------------------

    public function testTheSubmitButtonSaysItWritesToTheCatalogueOnTheDevPath(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entry = $this->seedEntry(self::TEST_KEY, 'Original text');
        $client->loginUser($this->createUser('deepl-submit-label@example.com'));
        $this->useDevTools();

        $crawler = $client->request('GET', '/fr/translate/'.$entry->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.tr-actions button[type="submit"]', 'Écrire dans le fichier du catalogue');
    }

    public function testTheSubmitButtonStillSaysProposeWhenNothingIsWritten(): void
    {
        // Same page, opt-in off: this submit really does propose, so the
        // button has to say so.
        $client = static::createClient();
        $client->disableReboot();
        $entry = $this->seedEntry(self::TEST_KEY, 'Original text');
        $client->loginUser($this->createUser('deepl-submit-label-off@example.com'));
        $this->useDevTools(catalogueWrite: '');

        $crawler = $client->request('GET', '/fr/translate/'.$entry->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.tr-actions button[type="submit"]', 'Proposer la traduction');
    }

    // --- The consent block (translations.md §7.3) -------------------------

    public function testTheConsentBlockIsAbsentOnDev(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entry = $this->seedEntry(self::TEST_KEY, 'Original text');
        $client->loginUser($this->createUser('deepl-consent-off@example.com'));
        $this->useDevTools();

        $crawler = $client->request('GET', '/fr/translate/'.$entry->getId());
        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('input[name="translation_proposal[consent]"]')->count());
        self::assertSame(0, $crawler->filter('.consent-ok')->count());
        self::assertSame(0, $crawler->filter('.consent')->count());
        self::assertSame(1, $crawler->filter('.tr-deepl-note')->count());
    }

    public function testTheConsentBlockStillRendersOffDev(): void
    {
        $client = static::createClient();
        $entry = $this->seedEntry(self::TEST_KEY, 'Original text');
        $client->loginUser($this->createUser('deepl-consent-on@example.com'));

        $crawler = $client->request('GET', '/fr/translate/'.$entry->getId());
        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('input[name="translation_proposal[consent]"]')->count());
        self::assertSame(0, $crawler->filter('.tr-deepl-note')->count());
    }
}
