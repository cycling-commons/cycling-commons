<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Translation;

use App\Entity\User;
use App\Translation\Entity\TranslationEntry;
use App\Translation\MarkerCodec;
use App\Translation\MarkerIndex;
use App\Translation\ProtectedKeys;
use App\Translation\TranslationCaches;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Translate mode: the marks on the page (translations.md §4.1).
 *
 * Test isolation: DAMA\DoctrineTestBundle wraps each test in a rolled-back
 * transaction.
 */
final class TranslateModeTest extends WebTestCase
{
    use FindsOrCreatesTranslationEntry;

    /**
     * The Dutch about page. Routes are localized by PATH, so there is no
     * `/nl/about`; `debug:router` names this one `about.nl`.
     */
    private const string DUTCH_PAGE = '/nl/over-ons';

    /** @param list<string> $roles */
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

        // A curator is redirected to /2fa/setup by TwoFactorSetupEnforcer on
        // every page until TOTP is configured (account-and-auth.md §4), which
        // would fail this suite on a redirect rather than on the marks.
        if (null !== $totpSecret) {
            $user->setTotpSecret($totpSecret);
            $user->setTwoFaEnabled($twoFaEnabled);
        }

        $em->persist($user);
        $em->flush();

        return $user;
    }

    /**
     * Seeds every row these tests need and returns the `nav.about` entry,
     * which the site nav renders on every page here.
     *
     * Every {@see ProtectedKeys} key gets a live row too, so the assertion
     * that they are unmarkable is about the exclusion rather than about a
     * missing row. Without a row there is nothing to mark at all, and an
     * assertion that a page carries no marks would pass for the wrong reason.
     */
    private function seedCatalogue(): TranslationEntry
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $entry = $this->findOrCreateEntry($em, 'nav.about', 'About');
        foreach (ProtectedKeys::KEYS as $key) {
            $this->findOrCreateEntry($em, $key, 'I agree.');
        }
        $this->invalidateCaches();

        return $entry;
    }

    private function invalidateCaches(): void
    {
        /** @var TranslationCaches $caches */
        $caches = static::getContainer()->get(TranslationCaches::class);
        $caches->invalidateAll();
    }

    /**
     * Turns the mode ON through the real form on /translate.
     *
     * The form is a toggle, so its `on` field is set explicitly: KernelBrowser
     * keeps the session across `loginUser()`, and a second call would
     * otherwise switch the mode back off.
     */
    private function turnOn(KernelBrowser $client): void
    {
        $crawler = $client->request('GET', '/nl/translate');
        $form = $crawler->filter('form.tr-mode-toggle')->form();
        $form['on'] = '1';
        $client->submit($form);
        self::assertResponseRedirects();
    }

    private function body(KernelBrowser $client): string
    {
        return (string) $client->getResponse()->getContent();
    }

    public function testOffByDefaultNoMarks(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('mode-off@example.com', 'hunter2secure!'));
        $this->seedCatalogue();
        $client->request('GET', self::DUTCH_PAGE);

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(MarkerCodec::START, $this->body($client));
    }

    public function testOnMarksStringsWithTheEntryIdAndNeverMarksProtectedKeys(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('mode-on@example.com', 'hunter2secure!'));
        $id = (int) $this->seedCatalogue()->getId();

        $this->turnOn($client);
        $client->request('GET', self::DUTCH_PAGE);
        $html = $this->body($client);

        self::assertStringContainsString(MarkerCodec::wrap($id, false, 'Over ons'), $html);
        self::assertStringNotContainsString(
            MarkerCodec::START,
            MarkerCodec::strip($html),
            'every START on the page belongs to a well-formed mark',
        );

        // Every consent contract has a live row and is still not markable: it
        // is the exact wording a rider agreed to, and it changes by a VERSION
        // bump in code and nowhere else (translations.md §4).
        /** @var MarkerIndex $markers */
        $markers = static::getContainer()->get(MarkerIndex::class);
        $map = $markers->forLocale('nl');
        self::assertArrayHasKey('nav.about', $map);
        foreach (ProtectedKeys::KEYS as $key) {
            self::assertArrayNotHasKey($key, $map, $key.' is a consent contract and must never be markable');
        }
    }

    public function testRiderOnEnglishGetsNoMarksButCuratorDoes(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('mode-en-rider@example.com', 'hunter2secure!'));
        $id = (int) $this->seedCatalogue()->getId();
        $this->turnOn($client);
        $client->request('GET', '/about');
        self::assertStringNotContainsString(MarkerCodec::START, $this->body($client));

        $client->loginUser($this->createUser(
            'mode-en-curator@example.com',
            'hunter2secure!',
            ['ROLE_CURATOR'],
            'JBSWY3DPEHPK3PXP',
            true,
        ));
        $this->turnOn($client);
        $client->request('GET', '/about');
        self::assertStringContainsString(MarkerCodec::wrap($id, false, 'About'), $this->body($client));
    }

    public function testTheMapCarriesNoMarks(): void
    {
        $client = static::createClient();
        // A curator, because /map has no locale prefix: a rider there is on
        // `en` and would be unmarked for want of the role, which would prove
        // nothing about the route rule.
        $client->loginUser($this->createUser(
            'mode-map@example.com',
            'hunter2secure!',
            ['ROLE_CURATOR'],
            'JBSWY3DPEHPK3PXP',
            true,
        ));
        $this->seedCatalogue();
        $this->turnOn($client);
        $client->request('GET', '/map');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(MarkerCodec::START, $this->body($client));
    }

    public function testMarkedPageIsNeverShareable(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('mode-cache@example.com', 'hunter2secure!'));
        $this->seedCatalogue();
        $this->turnOn($client);
        $client->request('GET', self::DUTCH_PAGE);

        // The page has to be a marked one for this to say anything at all.
        self::assertStringContainsString(MarkerCodec::START, $this->body($client));

        $cc = (string) $client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cc);
        self::assertStringNotContainsString('s-maxage', $cc);
    }

    /**
     * The stale bit, end to end: an entry whose English moved after the last
     * translation is marked with the flag set, which is what the bar colours
     * amber (translations.md §4.1).
     */
    public function testAStaleStringIsMarkedStale(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('mode-stale@example.com', 'hunter2secure!'));

        $entry = $this->seedCatalogue();
        $id = (int) $entry->getId();
        // A curator's approved English edit moves english_version and leaves
        // yaml_english_version behind, so every locale with no overlay is now
        // behind the English (translations.md §3.3).
        $entry->applyApprovedEnglish('About us');
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->flush();
        $this->invalidateCaches();

        $this->turnOn($client);
        $client->request('GET', self::DUTCH_PAGE);
        $html = $this->body($client);

        self::assertStringContainsString(MarkerCodec::wrap($id, true, 'Over ons'), $html);
        self::assertStringNotContainsString(MarkerCodec::wrap($id, false, 'Over ons'), $html);
    }

    /**
     * A POST is never marked, whatever else holds.
     *
     * `POST /settings/export` renders `export.readme` through the translator
     * into a ZIP, and a translated string inside a downloadable artifact is
     * unreachable by both response nets (translations.md §4.1). The guard is
     * on the method rather than on that one route, so this pins the rule where
     * it is cheap to see: the same translate form, re-rendered after an
     * invalid submit, which is the drawer's own POST.
     */
    public function testAPostResponseCarriesNoMarks(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('mode-post@example.com', 'hunter2secure!'));
        $id = (int) $this->seedCatalogue()->getId();
        // The translate pages wear the account shell, not the site nav, so
        // `nav.about` is not on them. This one is: it is the page's kicker.
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->findOrCreateEntry($em, 'translate.kicker', 'Translate');
        $this->invalidateCaches();
        $this->turnOn($client);

        $crawler = $client->request('GET', '/nl/translate/'.$id);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(MarkerCodec::START, $this->body($client), 'the GET of this form is marked');

        $form = $crawler->filter('form[name="translation_proposal"]')->form();
        $form['translation_proposal[value]'] = '';
        $client->submit($form);

        // An empty value is refused, so the POST answers the same page again
        // rather than redirecting (Symfony renders an invalid form as 422):
        // the body really is a rendered page, and the string that was marked a
        // moment ago is now bare.
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $posted = $this->body($client);
        self::assertStringContainsString('Vertalingen', $posted);
        self::assertStringNotContainsString(MarkerCodec::START, $posted);
    }

    public function testModeRouteNeedsCsrf(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('mode-csrf@example.com', 'hunter2secure!'));
        $client->request('POST', '/nl/translate/mode', ['on' => '1', '_csrf_token' => 'nope']);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * The chooser's second door (translations.md §4.1): posting the
     * "translate on the page" action for a language turns the session flag
     * on AND lands the reader on the home page in that language, not on
     * `/translate` and not on whatever page they happened to be on. This is
     * the exact discoverability bug: a non-curator on the English home page
     * never sees the in-page toggle at all (English is curator-only, §4.2),
     * so the chooser is the only place they can reach translate mode from.
     */
    public function testChooserTranslateOnPageDoorTurnsModeOnAndLandsOnTheHomePageInThatLocale(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('chooser-onpage@example.com', 'hunter2secure!'));

        $crawler = $client->request('GET', '/translate');
        self::assertResponseIsSuccessful();

        $form = $crawler->filterXPath(
            '//form[input[@name="locale" and @value="nl"]][input[@name="on" and @value="1"]]',
        )->form();
        $client->submit($form);

        /** @var UrlGeneratorInterface $urlGenerator */
        $urlGenerator = static::getContainer()->get(UrlGeneratorInterface::class);
        self::assertResponseRedirects($urlGenerator->generate('home', ['_locale' => 'nl']));

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        // Mode is on AND the reader is on the Dutch site: the bar renders,
        // which it only does while `translate_mode()` is true for this
        // request's locale (translations.md §4.1).
        self::assertStringContainsString('id="tr-bar"', $this->body($client));
    }

    /**
     * A toggle that sends no `locale` field (the account chip, the on-page
     * bar, the /translate list page's own toggle) must keep working exactly
     * as before: back to the same-host page the request came from, or
     * `/translate` with no Referer at all. The chooser's new `locale`
     * branch in `TranslateController::mode()` must not swallow this path.
     */
    public function testATogglerWithNoLocaleStillFollowsTheRefererFallback(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('mode-referer@example.com', 'hunter2secure!'));

        $crawler = $client->request('GET', '/nl/translate');
        $token = (string) $crawler->filter('form.tr-mode-toggle input[name="_csrf_token"]')->attr('value');

        $client->request(
            'POST',
            '/nl/translate/mode',
            ['on' => '1', '_csrf_token' => $token],
            [],
            ['HTTP_REFERER' => 'http://localhost'.self::DUTCH_PAGE],
        );

        self::assertResponseRedirects(self::DUTCH_PAGE);
    }

    /**
     * The on-page bar and its script (translations.md §4.1, task-10-brief.md)
     * appear only while the mode is on, and never otherwise: a page with the
     * mode off must stay byte-identical to today.
     *
     * task-10-brief.md's Step 7 snippet requests `/nl/about`, but that path
     * matches no route (`debug:router` names the Dutch about page
     * `about.nl` at `/nl/over-ons`, exactly as {@see DUTCH_PAGE} and every
     * other test in this class already says) and would 404 both times,
     * making the assertions pass for the wrong reason. Using DUTCH_PAGE here
     * is that fix, not a behaviour change.
     */
    public function testBarAndScriptRenderOnlyWhenActive(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('bar@example.com', 'hunter2secure!'));
        $html = (string) $client->request('GET', self::DUTCH_PAGE)->html();
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('id="tr-bar"', $html);
        self::assertStringNotContainsString('translate-mode', $html);

        $this->turnOn($client);
        $html = (string) $client->request('GET', self::DUTCH_PAGE)->html();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('id="tr-bar"', $html);
        self::assertStringContainsString('js/translate-mode', $html);
        // Base and suffix, not one URL the script string-replaces inside:
        // the id goes between them (translations.md §4.1).
        self::assertStringContainsString('data-edit-base="/nl/translate/"', $html);
        self::assertStringContainsString('data-edit-suffix="?embed=1"', $html);

        // The two counts are template-owned <b> elements beside translated
        // labels. Nothing from the catalogue passes through |raw here: an
        // overlay is untrusted text (translations.md §3, §8), and this bar
        // held the branch's only |raw on a catalogue string. Marks are
        // stripped first: the mode is on, so the two labels are themselves
        // marked strings (the script strips marks inside #tr-bar). The raw
        // response, not the crawler's re-serialisation, which rewrites a
        // valueless attribute as `data-tr-count=""`.
        self::assertStringContainsString(
            '<b data-tr-count>0</b> teksten · <b data-tr-stale>0</b> verouderd',
            MarkerCodec::strip($this->body($client)),
        );
    }

    /**
     * Regression pin: every translate-mode toggle form (the account chip's
     * two branches, the on-page bar, and the /translate list page) renders
     * its CSRF field as "_csrf_token", never Symfony's default "_token".
     *
     * All of these render in shared chrome, ahead of a page's own content,
     * so a "_token" field here would be the FIRST one in the document. A
     * test (or any scraper) that reads the first `name="_token" value="..."`
     * match to drive a page's own form ({@see
     * \App\Tests\Moderation\RegionAboutTextTest::token()} does exactly this)
     * would then capture the chip's token instead of the page's, submit
     * it, and get "Invalid CSRF token": a translate-mode change three files
     * away silently breaking an unrelated form on an unrelated page. That is
     * exactly what happened here once already (this task's first pass named
     * the field "_token"), which is why this pin exists.
     */
    public function testChromeCsrfFieldNeverShadowsAPageOwnToken(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('chrome-csrf@example.com', 'hunter2secure!'));
        $this->turnOn($client);

        // The Dutch about page: the account chip (in the topnav) AND the
        // on-page bar are both rendered here, so both toggle forms are on
        // the page at once.
        $html = (string) $client->request('GET', self::DUTCH_PAGE)->html();
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('name="_token"', $html,
            'a translate-mode toggle rendered "_token" and would shadow a page\'s own CSRF field');
        self::assertStringContainsString('name="_csrf_token"', $html);
    }

    /**
     * The account chip's Translate row badges the whole-locale stale count,
     * but only while translate mode is on (translations.md §4.1). A rider
     * who is not translating sees no new chrome at all, even when the
     * locale has stale entries.
     */
    public function testTheAccountMenuBadgesTheStaleCountOnlyWhileTheModeIsOn(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('stale-badge@example.com', 'hunter2secure!'));
        $entry = $this->seedCatalogue();

        // Off: no badge, whatever the stale count is.
        $client->request('GET', '/nl/over-ons');
        self::assertSame(0, $client->getCrawler()->filter('.acct-count.tr-stale-count')->count());

        $this->turnOn($client);
        $client->request('GET', '/nl/over-ons');
        self::assertSame(0, $client->getCrawler()->filter('.acct-count.tr-stale-count')->count(), 'nothing stale yet');

        // The EntityManager is fetched here, after the requests above, not
        // before: KernelBrowser reboots the kernel between requests, so an
        // EntityManager grabbed earlier belongs to a container that no
        // longer exists and a flush() through it never reaches the row the
        // next request's connection reads (the same trap documented at
        // MediaUploadEndpointTest::scanner()).
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $entry = $em->getRepository(TranslationEntry::class)->find($entry->getId());
        self::assertNotNull($entry);
        $entry->applyApprovedEnglish('Moved English');
        $em->flush();
        static::getContainer()->get(TranslationCaches::class)->invalidateAll();

        $crawler = $client->request('GET', '/nl/over-ons');
        self::assertSame('1', trim($crawler->filter('.acct-count.tr-stale-count')->text()));

        // The chip renders the Translate row twice, byte-identical, in a
        // "has work" branch (curator or admin) and a plain-member branch.
        // Only the plain-member one is reached above, so the same page is
        // asked again as a curator: a copy-paste pair is only pinned if both
        // copies are covered.
        $client->loginUser($this->createUser(
            'stale-badge-curator@example.com',
            'hunter2secure!',
            ['ROLE_CURATOR'],
            'JBSWY3DPEHPK3PXP',
            true,
        ));
        $this->turnOn($client);
        $crawler = $client->request('GET', '/nl/over-ons');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('.acct-group-personal')->count(), 'the curator branch of the chip');
        self::assertSame('1', trim($crawler->filter('.acct-count.tr-stale-count')->text()));
    }
}
