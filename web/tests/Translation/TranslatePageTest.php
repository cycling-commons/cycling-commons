<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Translation;

use App\Entity\User;
use App\Translation\Entity\TranslationEntry;
use App\Translation\Entity\TranslationProposal;
use App\Translation\ProposalService;
use App\Translation\TranslationProposalStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Rider /translate surface (translations.md §4).
 *
 * Test isolation: DAMA\DoctrineTestBundle wraps each test in a rolled-back transaction.
 */
final class TranslatePageTest extends WebTestCase
{
    use FindsOrCreatesTranslationEntry;

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

        // After CI sync the catalogue is fully populated; search so the key is on the page.
        $client->request('GET', '/fr/translate?q=home.cta_map');
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
        // After CI sync the table holds every YAML key; assert the filtered
        // result rows specifically (not the whole HTML document).
        self::assertDoesNotMatchRegularExpression('/class="row-key">\s*nav\.map\s*</', $html);
        preg_match_all('/class="row-key">\s*([^<]+?)\s*</', $html, $keys);
        self::assertNotEmpty($keys[1]);
        foreach ($keys[1] as $key) {
            self::assertStringContainsString('cta_map', $key);
        }
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

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role="alert"]', 'Proposition reçue — un curateur la relira.');

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

    public function testStandingConsentReplacesTheTickAfterFirstAgreement(): void
    {
        $client = static::createClient();
        $user = $this->createUser('translate-standing@example.com', 'hunter2secure!');
        $first = $this->seedEntry('home.cta_map', 'Explore the map');
        $later = $this->seedEntry('nav.home', 'Home');
        $client->loginUser($user);

        $crawler = $client->request('GET', '/fr/translate/'.$first->getId());
        self::assertSame(1, $crawler->filter('input[name="translation_proposal[consent]"]')->count());
        self::assertSame(0, $crawler->filter('.consent-ok')->count());

        $form = $crawler->filter('form[name="translation_proposal"]')->form([
            'translation_proposal[value]' => 'Explorer la carte',
            'translation_proposal[consent]' => true,
        ]);
        $client->submit($form);
        self::assertResponseRedirects();

        $crawler = $client->request('GET', '/fr/translate/'.$later->getId());
        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('input[name="translation_proposal[consent]"]')->count());
        self::assertSame(1, $crawler->filter('.consent-ok')->count());
        self::assertSelectorExists('.consent-ok time');

        $before = $this->proposalCount();
        $form = $crawler->filter('form[name="translation_proposal"]')->form([
            'translation_proposal[value]' => 'Accueil',
        ]);
        $client->submit($form);
        self::assertResponseRedirects();
        self::assertSame($before + 1, $this->proposalCount());
    }

    public function testGermanPrefixWorks(): void
    {
        $client = static::createClient();
        $user = $this->createUser('translate-de@example.com', 'hunter2secure!');
        $this->seedEntry('home.cta_map', 'Explore the map');
        $client->loginUser($user);

        $client->request('GET', '/de/translate?q=home.cta_map');
        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('home.cta_map', $html);
    }

    public function testPostWithoutCsrfCreatesNoProposal(): void
    {
        $client = static::createClient();
        $user = $this->createUser('translate-csrf@example.com', 'hunter2secure!');
        $entry = $this->seedEntry('home.cta_map', 'Explore the map');
        $client->loginUser($user);

        $before = $this->proposalCount();
        $client->request('POST', '/fr/translate/'.$entry->getId(), [
            'translation_proposal' => [
                '_token' => 'not-a-token',
                'value' => 'Explorer la carte',
                'consent' => '1',
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($before, $this->proposalCount());
    }

    public function testMarkupNoteForStrongTag(): void
    {
        $client = static::createClient();
        $user = $this->createUser('translate-markup@example.com', 'hunter2secure!');
        $entry = $this->seedEntry('test.markup.strong', 'Keep the <strong>tags</strong>.');
        $client->loginUser($user);

        $client->request('GET', '/fr/translate/'.$entry->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.note');
    }

    public function testRichPreviewStripsScript(): void
    {
        $client = static::createClient();
        $user = $this->createUser('translate-script@example.com', 'hunter2secure!');
        $entry = $this->seedEntry('home.cta_map', 'Explore the map');
        $client->loginUser($user);

        $crawler = $client->request('GET', '/fr/translate/'.$entry->getId());
        $form = $crawler->filter('form[name="translation_proposal"]')->form([
            'translation_proposal[value]' => '<script>alert(1)</script><b>ok</b>',
            'translation_proposal[consent]' => false,
        ]);
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        $preview = $client->getCrawler()->filter('.preview')->html();
        self::assertStringNotContainsString('<script>', $preview);
        self::assertStringContainsString('<b>ok</b>', $preview);
    }

    public function testCatalogueShowsPersonalMenu(): void
    {
        $client = static::createClient();
        $user = $this->createUser('translate-chrome@example.com', 'hunter2secure!');
        $client->loginUser($user);

        $crawler = $client->request('GET', '/fr/translate');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('nav.dtabs')->count());
        self::assertSame(1, $crawler->filter('nav.dtabs a[href$="/fr/translate"].on, nav.dtabs a[href$="/translate"].on')->count());
        self::assertSame(1, $crawler->filter('.mh .lead')->count());
        $html = (string) $crawler->filter('#main')->html();
        $h1 = strpos($html, '<h1');
        $lead = strpos($html, 'class="lead"');
        $chips = strpos($html, 'class="lfilter"');
        self::assertNotFalse($h1);
        self::assertNotFalse($lead);
        self::assertNotFalse($chips);
        self::assertLessThan($lead, $h1, 'the lead belongs under the title, not under the chips');
        self::assertLessThan($chips, $lead);
    }

    public function testEditShowsPersonalMenu(): void
    {
        $client = static::createClient();
        $user = $this->createUser('translate-edit-chrome@example.com', 'hunter2secure!');
        $entry = $this->seedEntry('home.cta_map', 'Explore the map');
        $client->loginUser($user);

        $crawler = $client->request('GET', '/fr/translate/'.$entry->getId());
        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('nav.dtabs')->count());
    }

    public function testContinueEditPrefillsPendingAndShowsLiveToProposedDiff(): void
    {
        $client = static::createClient();
        $user = $this->createUser('translate-continue@example.com', 'hunter2secure!');
        $entry = $this->seedEntry('test.tr.continue.diff', 'Water and views');
        $client->loginUser($user);

        static::getContainer()->get(ProposalService::class)
            ->submit($user, $entry, 'nl', 'waterpunten en uitzichten', true);

        $crawler = $client->request('GET', '/nl/translate/'.$entry->getId());

        self::assertResponseIsSuccessful();
        $textarea = $crawler->filter('textarea[name="translation_proposal[value]"]');
        self::assertSame(1, $textarea->count());
        self::assertStringContainsString('waterpunten en uitzichten', (string) $textarea->text());
        self::assertSame(1, $crawler->filter('.tr-change')->count());
        self::assertSelectorTextContains('.tr-change .q-now', 'waterpunten');
        $html = (string) $crawler->filter('.dbody')->html();
        $changePos = strpos($html, 'tr-change');
        $textareaPos = strpos($html, 'translation_proposal[value]');
        self::assertNotFalse($changePos);
        self::assertNotFalse($textareaPos);
        self::assertLessThan($textareaPos, $changePos, 'word diff must sit above the proposed textarea');
    }

    public function testFirstEditHasEmptyTextareaAndNoChangeDiff(): void
    {
        $client = static::createClient();
        $user = $this->createUser('translate-first-edit@example.com', 'hunter2secure!');
        $entry = $this->seedEntry('test.tr.first.edit', 'First visit empty');
        $client->loginUser($user);

        $crawler = $client->request('GET', '/nl/translate/'.$entry->getId());

        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('.tr-change')->count());
        $textarea = $crawler->filter('textarea[name="translation_proposal[value]"]');
        self::assertSame(1, $textarea->count());
        self::assertSame('', trim((string) $textarea->text()));
    }
}
