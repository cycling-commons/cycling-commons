<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Translation;

use App\Entity\User;
use App\Translation\Entity\TranslationEntry;
use App\Translation\Entity\TranslationOverlay;
use App\Translation\Entity\TranslationProposal;
use App\Translation\ProposalService;
use App\Translation\TranslationCaches;
use App\Translation\TranslationProposalStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
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
        ?string $totpSecret = null,
        bool $twoFaEnabled = false,
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

        // ROLE_CURATOR (and ROLE_ADMIN through the hierarchy) is redirected
        // to /2fa/setup by TwoFactorSetupEnforcer on every page until TOTP is
        // configured (docs/specs/account-and-auth.md §4). Any test that logs
        // in a curator must set these, or it fails on a redirect rather than
        // on the feature under test (see ModerateTranslationsTest).
        if (null !== $totpSecret) {
            $user->setTotpSecret($totpSecret);
            $user->setTwoFaEnabled($twoFaEnabled);
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
        // Scoped to the page body: the floating bug button
        // (contact-and-support.md §5) renders its own panel with two textareas
        // on every page, and they are not this page's edit boxes.
        self::assertSame(0, $crawler->filter('#main textarea')->count());
    }

    /**
     * The chooser is a fork in the road, not a dead end (translations.md
     * §4.1). A non-curator never sees a translatable locale by default
     * (English is the site default and English is curator-only, §4.2), so
     * every card here must offer BOTH the catalogue list and the on-page
     * door, or the second feature stays undiscoverable to exactly the rider
     * it was built for.
     */
    public function testChooserOffersBothBrowseAndTranslateOnThePageForEveryLanguage(): void
    {
        $client = static::createClient();
        $user = $this->createUser('chooser-both-doors@example.com', 'hunter2secure!');
        $client->loginUser($user);

        $crawler = $client->request('GET', '/translate');
        self::assertResponseIsSuccessful();
        $html = (string) $crawler->filter('#main')->html();

        foreach (['fr', 'nl', 'de', 'es'] as $loc) {
            self::assertStringContainsString('/'.$loc.'/translate', $html, $loc.' is missing its catalogue link');
            self::assertSame(
                1,
                $crawler->filterXPath(
                    '//form[input[@name="locale" and @value="'.$loc.'"]]'
                    .'[input[@name="on" and @value="1"]]'
                    .'[input[@name="_csrf_token"]]',
                )->count(),
                $loc.' is missing its "translate on the page" door',
            );
        }

        // The shared-chrome CSRF field name, never Symfony's default: a bare
        // "_token" here would shadow a page's own form for anything reading
        // the first match in raw HTML (translations.md §4.1).
        self::assertStringNotContainsString('name="_token"', $html);
        self::assertStringContainsString('name="_csrf_token"', $html);
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

    public function testStaleRowsCarryTagAndSortFirstAndChipFilters(): void
    {
        $client = static::createClient();
        $user = $this->createUser('stale-list@example.com', 'hunter2secure!');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $fresh = $this->seedEntry('zzsort.aaa.fresh', 'Fresh');
        $stale = $this->seedEntry('zzsort.zzz.stale', 'Photos up to 5 MB');
        $translator = static::getContainer()->get('translator');
        $dutchBefore = $translator->trans('zzsort.zzz.stale', [], 'messages', 'nl');
        $stale->applyApprovedEnglish('Photos up to 10 MB');
        $em->flush();
        static::getContainer()->get(TranslationCaches::class)->invalidateAll();
        $client->loginUser($user);

        // The list pages at 25 rows and this environment's catalogue may hold
        // the whole 3,855-key projection, so the two seeded rows are pinned to
        // one page by their shared key prefix. Ordering is unaffected: the
        // filter narrows the set, the SQL still sorts stale first.
        $html = (string) $client->request('GET', '/nl/translate?q=zzsort.')->html();
        self::assertStringContainsString('v1 → v2', $html);
        self::assertLessThan(strpos($html, 'zzsort.aaa.fresh'), strpos($html, 'zzsort.zzz.stale'), 'stale sorts first');

        $crawler = $client->request('GET', '/nl/translate?stale=1&q=zzsort.');
        self::assertStringContainsString('zzsort.zzz.stale', $crawler->html());
        self::assertStringNotContainsString('zzsort.aaa.fresh', $crawler->filter('#main')->html());

        // A stale translation STAYS LIVE: stale is a work list, never a
        // fallback to English (translations.md §4, owner decision 2026-08-31).
        // Pin that at the serving level, not just on the /translate list: the
        // Dutch wording the site's translator hands out for this key is
        // unaffected by the English change that just made it stale.
        $dutchAfter = $translator->trans('zzsort.zzz.stale', [], 'messages', 'nl');
        self::assertSame($dutchBefore, $dutchAfter, 'a stale translation keeps serving its unchanged wording');
        self::assertNotSame('Photos up to 10 MB', $dutchAfter, 'a stale translation must never silently become the new English');
    }

    public function testCuratorSeesEnglishCatalogueAndRiderSeesChooser(): void
    {
        $client = static::createClient();
        $this->seedEntry('nav.map', 'Map');
        $rider = $this->createUser('en-list-rider@example.com', 'hunter2secure!');
        $client->loginUser($rider);
        $crawler = $client->request('GET', '/translate');
        self::assertStringContainsString('/fr/translate', $crawler->html());

        $curator = $this->createUser('en-list-curator@example.com', 'hunter2secure!', ['ROLE_CURATOR'], totpSecret: 'JBSWY3DPEHPK3PXP', twoFaEnabled: true);
        $client->loginUser($curator);
        $crawler = $client->request('GET', '/translate?q=nav.map');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('nav.map', $crawler->filter('#main')->html());
        self::assertStringContainsString('/translate/', $crawler->filter('#main a.tr-row')->attr('href'));
    }

    public function testCuratorEnglishFormHasNoConsentAndSubmits(): void
    {
        $client = static::createClient();
        $entry = $this->seedEntry('nav.map', 'Map');
        $curator = $this->createUser('en-form@example.com', 'hunter2secure!', ['ROLE_CURATOR'], totpSecret: 'JBSWY3DPEHPK3PXP', twoFaEnabled: true);
        $client->loginUser($curator);

        $crawler = $client->request('GET', '/translate/'.$entry->getId());
        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('#main input[name="translation_proposal[consent]"]')->count());

        $form = $crawler->filter('#main form.tr-form')->form(['translation_proposal[value]' => 'Map view']);
        $client->submit($form);
        self::assertResponseRedirects('/translate');
        self::assertSame(1, $this->proposalCount());
    }

    public function testEmbedFrameRendersWithoutChromeAndAnswersSentOnPost(): void
    {
        $client = static::createClient();
        $entry = $this->seedEntry('nav.map', 'Map');
        $user = $this->createUser('embed@example.com', 'hunter2secure!');
        $client->loginUser($user);

        $crawler = $client->request('GET', '/nl/translate/'.$entry->getId().'?embed=1');
        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('nav.topnav')->count());
        self::assertSame(1, $crawler->filter('form.tr-form')->count());

        $form = $crawler->filter('form.tr-form')->form([
            'translation_proposal[value]' => 'Kaart',
            'translation_proposal[consent]' => true,
        ]);
        $client->submit($form);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('tr-embed-sent', (string) $client->getResponse()->getContent());
    }

    /**
     * A drawer POST leaves NOTHING in the flash bag (translations.md §4.1).
     *
     * translate/embed.html.twig renders the sent state from its own key and
     * never drains the success bag on that branch, so a success flash added
     * on the drawer path would survive in the session and reappear as a
     * duplicate banner on the translator's next full page load, on a page
     * that has nothing to do with the string they just proposed.
     */
    public function testADrawerPostLeavesNoPendingFlash(): void
    {
        $client = static::createClient();
        $entry = $this->seedEntry('nav.map', 'Map');
        $client->loginUser($this->createUser('embed-flash@example.com', 'hunter2secure!'));

        $crawler = $client->request('GET', '/nl/translate/'.$entry->getId().'?embed=1');
        self::assertResponseIsSuccessful();
        $client->submit($crawler->filter('form.tr-form')->form([
            'translation_proposal[value]' => 'Kaart',
            'translation_proposal[consent]' => true,
        ]));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('tr-embed-sent', (string) $client->getResponse()->getContent());

        $session = $client->getRequest()->getSession();
        self::assertInstanceOf(FlashBagAwareSessionInterface::class, $session);
        self::assertSame([], $session->getFlashBag()->peekAll(), 'the drawer path must add no flash');

        // And nothing surfaces on the next full page load either.
        $next = $client->request('GET', '/nl/translate');
        self::assertResponseIsSuccessful();
        self::assertSame(0, $next->filter('.flash-success')->count());
    }

    /**
     * translate.consent.standing is an overlayable key exactly like any
     * other catalogue string (translations.md §3, §8): a curator-approved
     * translation of it must not be able to inject live markup into the
     * standing-consent notice. Only the template's own %date% substitution
     * (the <time> element) is trusted; the surrounding overlay text is not,
     * and must render escaped even though the notice as a whole is |raw.
     */
    public function testStandingConsentOverlayMarkupIsEscaped(): void
    {
        $client = static::createClient();
        $user = $this->createUser('translate-standing-xss@example.com', 'hunter2secure!');
        $first = $this->seedEntry('home.cta_map', 'Explore the map');
        $later = $this->seedEntry('nav.home', 'Home');
        $standingEntry = $this->seedEntry(
            'translate.consent.standing',
            'You agreed to the translation licence on %date%. Your translations join the Commons under CC BY-SA 4.0.',
        );
        $client->loginUser($user);

        // Give this rider a standing consent record the same way a real
        // proposal does: ProposalService calls TranslationConsentService::record()
        // when a proposal is submitted with consent checked.
        $crawler = $client->request('GET', '/fr/translate/'.$first->getId());
        $form = $crawler->filter('form[name="translation_proposal"]')->form([
            'translation_proposal[value]' => 'Explorer la carte',
            'translation_proposal[consent]' => true,
        ]);
        $client->submit($form);
        self::assertResponseRedirects();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        // The client reboots the kernel on each request, so $standingEntry
        // (fetched before the submit above) belongs to a stale EntityManager.
        // Re-find it through the current one before wiring the overlay to it.
        $standingEntry = $em->find(TranslationEntry::class, $standingEntry->getId());
        self::assertNotNull($standingEntry);
        $em->persist(new TranslationOverlay(
            $standingEntry,
            'fr',
            'Vous avez accepte <b onclick="alert(1)">le contrat</b> le %date%. <script>alert(1)</script>',
            null,
            null,
            $standingEntry->getEnglishVersion(),
        ));
        $em->flush();
        static::getContainer()->get(TranslationCaches::class)->invalidateAll();

        $crawler = $client->request('GET', '/fr/translate/'.$later->getId());
        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('.consent-ok')->count());

        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('<b onclick="alert(1)">', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;b onclick=&quot;alert(1)&quot;&gt;', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);

        // The trusted %date% substitution still produces a real <time> element.
        self::assertSelectorExists('.consent-ok time.consent-when');
    }
}
