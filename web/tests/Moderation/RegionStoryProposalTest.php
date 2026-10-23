<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\Region;
use App\Catalog\Entity\Submission;
use App\Catalog\Links\LinkVerdictStore;
use App\Catalog\Links\SafeBrowsing;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use App\Contribution\PlaceText;
use App\Contribution\PlaceTextProposals;
use App\Contribution\PlaceTextRefused;
use App\Contribution\StoryLinkCheck;
use App\Contribution\SubmissionChangeSummary;
use App\Entity\User;
use App\Links\WebsiteChecker;
use App\Moderation\Entity\ModeratorArea;
use App\Moderation\ModerationScopeProvider;
use App\Moderation\ModerationService;
use App\Moderation\SubmissionQueue;
use App\Moderation\TextLinksUnconfirmedException;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A region's "History, nature and culture" text: anyone signed in writes,
 * edits or translates it, citing its sources in place, and a curator of the
 * region approves it, through the one Text queue
 * (moderation-and-contribution.md §3.1c).
 *
 * Two regions far from any real one. DAMA wraps each test in a rolled-back
 * transaction.
 */
final class RegionStoryProposalTest extends WebTestCase
{
    private const string TEXT = "Polders everywhere.\n\nWind did the work.";
    private const string LINKED = "The [Stelling van Amsterdam](https://www.stelling-amsterdam.nl) rings the city.\n\nWind did the work.";

    public function testTheRegionPageShowsTheTextItsCitationsAndItsCredit(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$a, $b] = $this->twoRegions();
        $this->story($a, 'en', self::LINKED);

        $crawler = $client->request('GET', '/regions/pstory-a');
        self::assertResponseIsSuccessful();
        $section = $crawler->filter('#story');
        self::assertSame('History, nature and culture', $section->filter('.kicker')->text());
        self::assertSame('Geography', $crawler->filter('.rg-about:not(.rg-story) .kicker')->text(), 'the first text is the geography text');
        self::assertSame('/regions#country-PN', $crawler->filter('.rhero .co a.co-link')->attr('href'), 'the country leads to its regions on the list');
        self::assertSame(['The Stelling van Amsterdam rings the city.', 'Wind did the work.'], $section->filter('.rg-story-text p')->each(static fn ($p): string => $p->text()), 'one paragraph per line');
        $cited = $section->filter('.rg-story-text p a');
        self::assertSame('Stelling van Amsterdam', $cited->text(), 'the words that describe the source are the link');
        self::assertSame('https://www.stelling-amsterdam.nl', $cited->attr('href'));
        self::assertSame('_blank', $cited->attr('target'), 'a citation opens in a new tab');
        self::assertSame('noopener noreferrer', $cited->attr('rel'), 'and is a followed editorial link, no nofollow');
        self::assertNull($section->filter('.rg-story-text')->attr('lang'), 'the reader\'s own language needs no lang attribute');
        self::assertStringStartsWith("Written by this region's curators,", $section->filter('.tc-line')->text(), 'a system text names the curators');
        self::assertSame('/regions/pstory-a/story?lang=en', $section->filter('.tc-acts a.ring-ico--edit')->attr('href'));
        self::assertCount(0, $section->filter('h3, ul'), 'no separate list of sources');

        // In another language: the English text, marked, with a way to translate it.
        $crawler = $client->request('GET', '/nl/regios/pstory-a');
        self::assertResponseIsSuccessful();
        $section = $crawler->filter('#story');
        self::assertSame('en', $section->filter('.rg-story-text')->attr('lang'));
        self::assertSame('Deze tekst is in het Engels. Niemand heeft hem nog vertaald. Vertaal hem', $section->filter('.rg-about-write')->text());
        self::assertSame('/nl/regions/pstory-a/story?lang=nl', $section->filter('.rg-about-write a')->attr('href'));

        // No text: one plain link to write one.
        $crawler = $client->request('GET', '/regions/pstory-b');
        $write = $crawler->filter('#story .rg-about-write a');
        self::assertSame('Write about the history, nature and culture of this region', $write->text());
        self::assertSame('/regions/pstory-b/story', $write->attr('href'));
        unset($b);
    }

    public function testARidersTextWaitsForACuratorOfTheRegionAndIsCreditedOnceApproved(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$a, $b] = $this->twoRegions();
        $rider = $this->user('pstory-rider1@example.com', [], null, 'Polderfietser');
        $curatorA = $this->user('pstory-cur-a1@example.com', ['ROLE_CURATOR'], $a);
        $curatorB = $this->user('pstory-cur-b1@example.com', ['ROLE_CURATOR'], $b);

        $client->loginUser($rider);
        $crawler = $client->request('GET', '/regions/pstory-a/story?lang=en');
        self::assertResponseIsSuccessful();
        // The region editor, opened at its history step (§3.1g).
        self::assertSame(['1 Figures', '2 Geography', '3 History, nature and culture'], $crawler->filter('.pt-tabs [role=tab]')->each(static fn ($t): string => $t->text()));
        self::assertSame('true', $crawler->filter('#pt-tab-story')->attr('aria-selected'), 'the pencil on the history text opens its step');
        self::assertSame('story', $crawler->filter('input[name=step]')->attr('value'));
        self::assertSame('', $crawler->filter('textarea#pt-story')->text('', false), 'no text yet, so the box starts empty');
        self::assertCount(0, $crawler->filter('textarea[name=sources]'), 'no sources box: the text cites in place');
        // The link editor: hidden until its script runs, fed the words the server refuses.
        self::assertCount(1, $crawler->filter('#pt-linkbar[hidden] #pt-link-open'));
        self::assertCount(1, $crawler->filter('#pt-step-story .pt-hl-wrap > textarea#pt-story[data-links]'));
        self::assertContains('click here', json_decode((string) $crawler->filter('textarea#pt-story')->attr('data-generic'), true, 512, \JSON_THROW_ON_ERROR));
        self::assertCount(1, $crawler->filter('#pt-links[hidden] ol#pt-links-list'));
        self::assertSame('/regions/links/check', $crawler->filter('#pt-links')->attr('data-check-url'), 'links are checked as they appear');
        self::assertNotEmpty($crawler->filter('#pt-links')->attr('data-check-token'));
        // How to write it, with the link example in the box's own tints.
        self::assertSame('How to write it', $crawler->filter('aside.pt-howto#pt-story-hint .pt-howto-h')->text());
        self::assertSame(['[Defence Line of Amsterdam]', '(https://whc.unesco.org/en/list/759)'], $crawler->filter('.pt-howto-ex mark')->each(static fn ($m): string => $m->text()), 'the example in the page\'s language');
        self::assertStringContainsString('at most 2,500 characters. Link addresses do not count.', $crawler->filter('#pt-story-hint li')->first()->text());
        self::assertSame('6000', $crawler->filter('textarea#pt-story')->attr('maxlength'), 'the box holds the addresses too');
        self::assertSame('2500', $crawler->filter('#pt-story-count')->attr('data-max'), 'the count is of what a reader sees');

        $crawler = $client->request('GET', '/nl/regions/pstory-a/story?lang=nl');
        self::assertSame(['[Stelling van Amsterdam]', '(https://www.stelling-amsterdam.nl)'], $crawler->filter('.pt-howto-ex mark')->each(static fn ($m): string => $m->text()));
        $crawler = $client->request('GET', '/regions/pstory-a/story?lang=en');
        self::assertCount(0, $crawler->filter('#pt-step-story #pt-source'), 'no Wikipedia question');
        self::assertStringStartsWith("What the land's history, nature and culture tell a rider.", $crawler->filter('#pt-step-story .pt-step-p')->text(), 'the step says which text this is');
        $client->submit($crawler->filter('#place-text-form')->form([
            'lang' => 'en', 'story' => "Polders everywhere.\r\n\r\nWind   did the work.", 'note' => 'From the museum.',
        ]));
        self::assertResponseIsSuccessful();

        $sub = $this->latestText((int) $rider->getId());
        self::assertSame(SubmissionStatus::Pending, $sub->getStatus());
        self::assertSame($a->getId(), $sub->getRegionId());
        self::assertSame(PlaceText::STORY, $sub->getPayload()['target']);
        self::assertSame(self::TEXT, $sub->getPayload()['text'], 'paragraphs kept, whitespace folded');
        self::assertEquals(['story:en' => ['was' => null, 'now' => self::TEXT]], $sub->getChanges());
        $labels = array_column(static::getContainer()->get(SubmissionChangeSummary::class)->rows($sub), 'label');
        self::assertSame(['History, nature and culture (EN)'], $labels);
        self::assertContains((int) $sub->getId(), $this->queueIds($curatorA));
        self::assertNotContains((int) $sub->getId(), $this->queueIds($curatorB));

        $this->proposals()->approve($this->fresh($sub), $curatorA, self::TEXT, null, '');

        $stored = $this->stored($a);
        self::assertSame(self::TEXT, $stored['en']['text']);
        self::assertArrayNotHasKey('sources', $stored['en']);
        self::assertSame($rider->getId(), $stored['en']['userId']);
        self::assertSame($curatorA->getId(), $stored['en']['approvedBy']);
        self::assertSame($sub->getId(), $stored['en']['submissionId']);

        $crawler = $client->request('GET', '/regions/pstory-a');
        self::assertStringStartsWith('Written by Polderfietser,', $crawler->filter('#story .tc-line')->text());
    }

    public function testATranslationStartsFromAnotherLanguagesText(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$a] = $this->twoRegions();
        $this->story($a, 'en', self::LINKED);
        $rider = $this->user('pstory-rider2@example.com', []);
        $curatorA = $this->user('pstory-cur-a2@example.com', ['ROLE_CURATOR'], $a);

        $client->loginUser($rider);
        $crawler = $client->request('GET', '/regions/pstory-a/story?lang=en');
        self::assertSame(self::LINKED, $crawler->filter('textarea#pt-story')->text('', false), 'an edit starts from the current text, its citations included');
        self::assertCount(0, $crawler->filter('#pt-translate:not([hidden])'));

        $crawler = $client->request('GET', '/regions/pstory-a/story?lang=nl');
        self::assertSame('', $crawler->filter('textarea#pt-story')->text('', false), 'a translation is written in an empty box');
        self::assertSame('To translate from (English)', $crawler->filter('#pt-translate figcaption')->text());
        self::assertSame(self::LINKED, $crawler->filter('#pt-translate blockquote')->text('', false), 'with the citations to carry over');

        $nl = "De [Stelling van Amsterdam](https://www.stelling-amsterdam.nl) omringt de stad.\nDe wind deed het werk.";
        // No Safe Browsing key in the suite: every link is "could not check", so the writer ticks.
        $client->submit($crawler->filter('#place-text-form')->form(['lang' => 'nl', 'story' => $nl, 'links_confirmed' => '1']));
        $sub = $this->latestText((int) $rider->getId());
        self::assertSame('nl', $sub->getPayload()['lang']);
        self::assertSame('nl', $sub->getLang(), 'the submission carries the language it is written in');
        self::assertEquals(['story:nl' => ['was' => null, 'now' => str_replace("\n", "\n\n", $nl)]], $sub->getChanges());

        $this->proposals()->approve($this->fresh($sub), $curatorA, (string) $sub->getPayload()['text'], null, '', true);
        $stored = $this->stored($a);
        self::assertSame(self::LINKED, $stored['en']['text'], 'the English text is untouched');
        self::assertSame(str_replace("\n", "\n\n", $nl), $stored['nl']['text']);

        $crawler = $client->request('GET', '/nl/regios/pstory-a');
        self::assertNull($crawler->filter('#story .rg-story-text')->attr('lang'));
        self::assertSame('Stelling van Amsterdam', $crawler->filter('#story .rg-story-text a')->text());
        self::assertCount(0, $crawler->filter('#story .rg-about-write'), 'translated: no request to translate');
    }

    public function testAnUnchangedTextAndAGenericLinkAreSentBack(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$a] = $this->twoRegions();
        $this->story($a, 'en', self::TEXT);
        $rider = $this->user('pstory-rider3@example.com', []);
        $region = $this->proposals()->region('pstory-a');
        self::assertNotNull($region);

        foreach ([
            [self::TEXT, 'place_text.error.unchanged'],
            ['The sluice ([source](https://www.hhnk.nl)).', 'place_text.error.generic_anchor'],
            ['The sluice: [Click here](https://www.hhnk.nl).', 'place_text.error.generic_anchor'],
        ] as [$text, $error]) {
            try {
                $this->proposals()->proposeStory($rider, $region, 'en', $text, '');
                self::fail($error.' expected');
            } catch (PlaceTextRefused $e) {
                self::assertSame($error, $e->getMessage());
            }
        }
    }

    public function testACuratorsOwnTextAppliesInsideTheirArea(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$a] = $this->twoRegions();
        $curatorA = $this->user('pstory-cur-a4@example.com', ['ROLE_CURATOR'], $a);

        $client->loginUser($curatorA);
        $crawler = $client->request('GET', '/regions/pstory-a/story?lang=en');
        $client->submit($crawler->filter('#place-text-form')->form(['lang' => 'en', 'story' => self::LINKED, 'links_confirmed' => '1']));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.csent h2', 'Changes live');
        self::assertSelectorTextContains('.pt-sent-list li', 'History, nature and culture');

        $stored = $this->stored($a);
        self::assertSame(self::LINKED, $stored['en']['text']);
        self::assertSame($curatorA->getId(), $stored['en']['userId']);
        self::assertSame($curatorA->getId(), $stored['en']['approvedBy']);
    }

    public function testACuratorCorrectsTheTextOnTheCorrectionFormAndApproves(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$a] = $this->twoRegions();
        $this->story($a, 'en', self::TEXT);
        $rider = $this->user('pstory-rider5@example.com', []);
        $curatorA = $this->user('pstory-cur-a5@example.com', ['ROLE_CURATOR'], $a);
        $region = $this->proposals()->region('pstory-a');
        self::assertNotNull($region);
        $typo = "Polders everywhere.\n\nWind did the wrok, see the [Stelling](https://www.stelling-amsterdam.nl).";
        $sub = $this->proposals()->proposeStory($rider, $region, 'en', $typo, '', true)['submission'];

        $client->loginUser($curatorA);
        $crawler = $client->request('GET', '/moderate/text/'.$sub->getId());
        self::assertResponseIsSuccessful();
        self::assertSame($typo, $crawler->filter('textarea#pt-text')->text('', false));
        self::assertCount(0, $crawler->filter('#pt-credit'), 'no Wikipedia credit to decide');

        $fixed = str_replace('wrok', 'work', $typo);
        $client->submit($crawler->selectButton('Approve')->form(['text' => $fixed, 'links_confirmed' => '1']));
        self::assertResponseRedirects();

        $stored = $this->stored($a);
        self::assertSame($fixed, $stored['en']['text']);
        self::assertSame($rider->getId(), $stored['en']['userId'], 'it stays the rider\'s text');
        $after = $this->fresh($sub);
        self::assertSame(SubmissionStatus::Approved, $after->getStatus());
        self::assertSame($typo, $after->getPayload()['_corrected']['from'] ?? null);
        self::assertEquals(['story:en' => ['was' => self::TEXT, 'now' => $fixed]], $after->getChanges());
    }

    public function testTheLimitCountsWhatAReaderSeesNotTheAddresses(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$a] = $this->twoRegions();
        $rider = $this->user('pstory-rider6@example.com', []);
        $region = $this->proposals()->region('pstory-a');
        self::assertNotNull($region);

        // 2,453 characters a reader sees and 3,000 more in an address: accepted.
        $long = str_repeat('a', 2440).' [the Stelling](https://www.stelling-amsterdam.nl/'.str_repeat('x', 3000).')';
        self::assertSame(2453, \App\Catalog\RegionStory::readableLength($long));
        self::assertFalse($this->proposals()->proposeStory($rider, $region, 'en', $long, '', true)['applied']);

        try {
            $this->proposals()->proposeStory($rider, $region, 'en', str_repeat('a', 2501), '');
            self::fail('more than 2,500 characters a reader sees is sent back');
        } catch (PlaceTextRefused $e) {
            self::assertSame('place_text.error.too_long', $e->getMessage());
            self::assertSame(['%max%' => '2500'], $e->params);
        }
        unset($a);
    }

    public function testALinkThatIsNotPlainlyFineNeedsTheWritersAndTheCuratorsTick(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$a] = $this->twoRegions();
        $this->story($a, 'en', self::TEXT);
        $web = new MockHttpClient(static function (string $method, string $url): MockResponse {
            if (str_contains($url, 'parked')) {
                return new MockResponse('<title>Buy this domain</title>', ['http_code' => 200]);
            }
            if (str_contains($url, 'dead')) {
                return new MockResponse('', ['error' => 'Could not resolve host: dead.test']);
            }

            return new MockResponse('<title>Archive</title>', ['http_code' => 200]);
        });
        static::getContainer()->set(WebsiteChecker::class, new WebsiteChecker($web, new NullLogger()));
        $google = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            return new MockResponse(str_contains((string) ($options['body'] ?? ''), 'malware.test') ? '{"matches":[{"threat":{"url":"https://malware.test/page"}}]}' : '{}');
        });
        static::getContainer()->set(SafeBrowsing::class, new SafeBrowsing($google, new NullLogger(), 'test-key'));
        $rider = $this->user('pstory-rider7@example.com', []);
        $curatorA = $this->user('pstory-cur-a7@example.com', ['ROLE_CURATOR'], $a);
        $region = $this->proposals()->region('pstory-a');
        self::assertNotNull($region);

        // Every link checked out: no tick asked.
        $fine = $this->proposals()->proposeStory($rider, $region, 'en', self::TEXT."\nSee [the archive](https://archive.test/a).", '');
        self::assertArrayNotHasKey('_links', $fine['submission']->getPayload());

        $text = self::TEXT."\nSee [the old dike](https://dead.test/a), [the shop](https://parked.test/) and [the page](https://malware.test/page).";
        try {
            $this->proposals()->proposeStory($rider, $region, 'en', $text, '');
            self::fail('links that are not plainly fine need the writer\'s tick');
        } catch (PlaceTextRefused $e) {
            self::assertSame('place_text.error.links_unconfirmed', $e->getMessage());
            self::assertSame(['%urls%' => 'https://dead.test/a, https://parked.test/, https://malware.test/page'], $e->params);
        }

        // Ticked: filed, never blocked, and the tick is kept with what was wrong.
        $sub = $this->proposals()->proposeStory($rider, $region, 'en', $text, '', true)['submission'];
        self::assertSame(SubmissionStatus::Pending, $sub->getStatus());
        $writer = $sub->getPayload()['_links']['writer'];
        self::assertSame($rider->getId(), $writer['by']);
        self::assertSame(['https://dead.test/a' => 'dead', 'https://parked.test/' => 'parked', 'https://malware.test/page' => 'unsafe'], $writer['problems']);

        // The curator approves only with a tick of their own: not from the drawer, not without it on the form.
        try {
            $this->moderation()->decide((int) $sub->getId(), 'approve', $curatorA, null);
            self::fail('the drawer\'s approve must send the curator to the form');
        } catch (TextLinksUnconfirmedException) {
            // A refusal (DecisionRefusal): rolled back, and the database session stays open.
            self::assertTrue(static::getContainer()->get(EntityManagerInterface::class)->isOpen());
        }
        try {
            $this->proposals()->approve($this->fresh($sub), $curatorA, $text, null, '');
            self::fail('the form\'s approve needs the curator\'s tick');
        } catch (PlaceTextRefused $e) {
            self::assertSame('place_text.error.links_unconfirmed', $e->getMessage());
        }
        $this->proposals()->approve($this->fresh($sub), $curatorA, $text, null, '', true);
        $after = $this->fresh($sub);
        self::assertSame(SubmissionStatus::Approved, $after->getStatus());
        self::assertSame($curatorA->getId(), $after->getPayload()['_links']['approved']['by']);
        self::assertSame(\App\Catalog\RegionStory::normaliseText($text), $this->stored($a)['en']['text']);

        /** @var Connection $db */
        $db = self::getContainer()->get(Connection::class);
        self::assertSame('unsafe', $db->fetchOne('SELECT verdict FROM link_verdict WHERE url_hash = :h', ['h' => LinkVerdictStore::hash('https://malware.test/page')]), 'the Safe Browsing verdict is kept for the desk');
    }

    /**
     * A link a curator confirmed on an approved text is not asked again, in
     * another language either, while it shows the same problem. An unsafe
     * link is asked every time, and so is one whose problem changed.
     */
    public function testALinkACuratorConfirmedOnceIsNotAskedAgain(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$a] = $this->twoRegions();
        $this->story($a, 'en', self::TEXT);
        $web = new MockHttpClient(static function (string $method, string $url): MockResponse {
            if (str_contains($url, 'dead')) {
                return new MockResponse('', ['error' => 'Could not resolve host: dead.test']);
            }
            if (str_contains($url, 'parked')) {
                return new MockResponse('<title>Buy this domain</title>', ['http_code' => 200]);
            }

            return new MockResponse('<title>Archive</title>', ['http_code' => 200]);
        });
        static::getContainer()->set(WebsiteChecker::class, new WebsiteChecker($web, new NullLogger()));
        $google = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            return new MockResponse(str_contains((string) ($options['body'] ?? ''), 'malware.test') ? '{"matches":[{"threat":{"url":"https://malware.test/page"}}]}' : '{}');
        });
        static::getContainer()->set(SafeBrowsing::class, new SafeBrowsing($google, new NullLogger(), 'test-key'));
        $curatorA = $this->user('pstory-cur-a9@example.com', ['ROLE_CURATOR'], $a);
        $rider = $this->user('pstory-rider9@example.com', []);
        $region = $this->proposals()->region('pstory-a');
        self::assertNotNull($region);

        // A rider's tick on a text still waiting confirms nothing for anyone else.
        $this->proposals()->proposeStory($rider, $region, 'fr', self::TEXT."\nVoir [la digue](https://dead.test/a).", '', true);
        try {
            $this->proposals()->proposeStory($curatorA, $region, 'de', self::TEXT."\nSiehe [den Deich](https://dead.test/a).", '');
            self::fail('a tick on a waiting text is no curator\'s confirmation');
        } catch (PlaceTextRefused $e) {
            self::assertSame(['%urls%' => 'https://dead.test/a'], $e->params);
        }

        // The curator's own English text, ticked, goes live: the tick is the approval's.
        $en = self::TEXT."\nSee [the old dike](https://dead.test/a), [the shop](https://parked.test/) and [the page](https://malware.test/page).";
        $this->proposals()->proposeStory($curatorA, $region, 'en', $en, '', true);

        // The Dutch text with the same dead link and the same shop needs no tick.
        $nl = self::TEXT."\nZie [de oude dijk](https://dead.test/a) en [de winkel](https://parked.test/).";
        $sub = $this->proposals()->proposeStory($curatorA, $region, 'nl', $nl, '')['submission'];
        self::assertSame(SubmissionStatus::Approved, $this->fresh($sub)->getStatus());
        self::assertSame(['https://dead.test/a', 'https://parked.test/'], $this->fresh($sub)->getPayload()['_links']['writer']['earlier'], 'kept as confirmed before, not as ticked now');

        // An unsafe link is asked again every time.
        try {
            $this->proposals()->proposeStory($curatorA, $region, 'nl', $nl."\nEn [de pagina](https://malware.test/page).", '');
            self::fail('an unsafe link is confirmed by hand every time');
        } catch (PlaceTextRefused $e) {
            self::assertSame(['%urls%' => 'https://malware.test/page'], $e->params);
        }

        // The form says so as the links appear.
        $verdicts = static::getContainer()->get(StoryLinkCheck::class)->checkLinks(['https://dead.test/a', 'https://malware.test/page', 'https://dead.test/other']);
        self::assertTrue($verdicts['https://dead.test/a']['confirmed']);
        self::assertFalse($verdicts['https://malware.test/page']['confirmed'], 'never for an unsafe link');
        self::assertFalse($verdicts['https://dead.test/other']['confirmed'], 'another address is another link');
    }

    /**
     * Every version is on the text's public list of changes (§3.1f): newest
     * first, its day without a time, who wrote it, and against the version
     * before only the words it changed. The credit line leads there (↺).
     */
    public function testEveryVersionIsOnTheTextsListOfChanges(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$a] = $this->twoRegions();
        $rider = $this->user('pstory-rider10@example.com', [], null, 'Polderfietser');
        $curatorA = $this->user('pstory-cur-a10@example.com', ['ROLE_CURATOR'], $a);
        $region = $this->proposals()->region('pstory-a');
        self::assertNotNull($region);

        $first = "Polders everywhere.\n\nWind did the work for three hundred years, mill by mill and dike by dike across the old lake bed.";
        $this->proposals()->proposeStory($curatorA, $region, 'en', $first, '');
        $second = str_replace('three hundred', 'four hundred', $first);
        $sub = $this->proposals()->proposeStory($rider, $region, 'en', $second, '')['submission'];
        $this->proposals()->approve($this->fresh($sub), $curatorA, $second, null, '');

        $crawler = $client->request('GET', '/regions/pstory-a');
        $icon = $crawler->filter('#story .tc-line a.ring-ico--history');
        self::assertSame('/regions/pstory-a/story/history?lang=en', $icon->attr('href'));
        self::assertSame('Changes to this text', $icon->attr('aria-label'));

        $crawler = $client->request('GET', '/regions/pstory-a/story/history?lang=en');
        self::assertResponseIsSuccessful();
        self::assertSame('History, nature and culture of Pstory A (English)', $crawler->filter('h1.th-h')->text());
        $versions = $crawler->filter('.th-list > .th-v');
        self::assertCount(2, $versions);
        self::assertSame('By Polderfietser', $versions->eq(0)->filter('.th-by')->text(), 'newest first, the rider named');
        self::assertSame('three', $versions->eq(0)->filter('.th-diff del')->text());
        self::assertSame('four', $versions->eq(0)->filter('.th-diff ins')->text());
        self::assertDoesNotMatchRegularExpression('/\d{1,2}:\d{2}/', $versions->eq(0)->filter('.th-meta time')->text(), 'a day, not a time');
        self::assertSame("By this region's curators", $versions->eq(1)->filter('.th-by')->text());
        self::assertStringContainsString('Polders everywhere.', $versions->eq(1)->filter('.th-text')->text(), 'the first version whole');
    }

    public function testTheFormChecksLinksAsTheyAppear(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->twoRegions();
        $web = new MockHttpClient(static function (string $method, string $url): MockResponse {
            if (str_contains($url, 'dead')) {
                return new MockResponse('', ['error' => 'Could not resolve host: dead.test']);
            }
            if (str_contains($url, 'parked')) {
                return new MockResponse('<title>Domain for sale</title>', ['http_code' => 200]);
            }

            return new MockResponse('<title>Archive</title>', ['http_code' => 200]);
        });
        static::getContainer()->set(WebsiteChecker::class, new WebsiteChecker($web, new NullLogger()));

        $client->request('POST', '/regions/links/check', [], [], ['CONTENT_TYPE' => 'application/json'], '{"token":"x","urls":["https://a.test/"]}');
        self::assertResponseRedirects(null, 302, 'a visitor signs in first');

        $client->loginUser($this->user('pstory-rider8@example.com', []));
        $crawler = $client->request('GET', '/regions/pstory-a/story?lang=en');
        $token = (string) $crawler->filter('#pt-links')->attr('data-check-token');
        $ask = static function (array $body) use ($client): array {
            $client->request('POST', '/regions/links/check', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body, \JSON_THROW_ON_ERROR));

            return [$client->getResponse()->getStatusCode(), json_decode((string) $client->getResponse()->getContent(), true)];
        };

        [$status, $data] = $ask(['token' => $token, 'urls' => ['https://archive.test/a', 'https://dead.test/b', 'https://parked.test/']]);
        self::assertSame(200, $status);
        self::assertSame(['site' => 'live', 'safety' => 'unknown', 'refused' => null, 'confirmed' => false], $data['links']['https://archive.test/a'], 'no Safe Browsing key in the suite: unknown');
        self::assertSame('dead', $data['links']['https://dead.test/b']['refused']);
        self::assertSame('parked', $data['links']['https://parked.test/']['refused']);

        self::assertSame(403, $ask(['token' => 'wrong', 'urls' => ['https://a.test/']])[0], 'the form\'s own token only');
        self::assertSame(422, $ask(['token' => $token, 'urls' => ['ftp://a.test/']])[0], 'http(s) addresses only');
        self::assertSame(422, $ask(['token' => $token, 'urls' => array_map(static fn (int $i): string => 'https://a'.$i.'.test/', range(1, 21))])[0], 'at most 20 a time');
        self::assertSame(422, $ask(['token' => $token, 'urls' => []])[0]);
    }

    /** @return array<string, array<string, mixed>> */
    private function stored(Region $region): array
    {
        /** @var Connection $db */
        $db = self::getContainer()->get(Connection::class);

        /* @var array<string, array<string, mixed>> */
        return json_decode((string) $db->fetchOne('SELECT story FROM region WHERE id = :id', ['id' => $region->getId()]), true, 512, \JSON_THROW_ON_ERROR);
    }

    private function story(Region $region, string $lang, string $text): void
    {
        /** @var Connection $db */
        $db = self::getContainer()->get(Connection::class);
        $db->executeStatement('UPDATE region SET story = CAST(:s AS jsonb) WHERE id = :id', [
            's' => json_encode([$lang => ['text' => $text, 'userId' => null, 'approvedBy' => null, 'submissionId' => null, 'at' => '2026-10-20T00:00:00+00:00']], \JSON_THROW_ON_ERROR),
            'id' => $region->getId(),
        ]);
    }

    /** @return array{0: Region, 1: Region} */
    private function twoRegions(): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $a = (new Region())->setSlug('pstory-a')->setName('Pstory A')->setCountryCode('PN')->setAreaKm2(1.0)
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[-141,-41],[-140,-41],[-140,-40],[-141,-40],[-141,-41]]]]}');
        $b = (new Region())->setSlug('pstory-b')->setName('Pstory B')->setCountryCode('CK')->setAreaKm2(1.0)
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[-121,-41],[-120,-41],[-120,-40],[-121,-40],[-121,-41]]]]}');
        $em->persist($a);
        $em->persist($b);
        $em->flush();

        return [$a, $b];
    }

    /** @param list<string> $roles */
    private function user(string $email, array $roles, ?Region $area = null, ?string $publicName = null): User
    {
        $c = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $c->get(EntityManagerInterface::class);
        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName($publicName ?? (strstr($email, '@', true) ?: $email));
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles($roles);
        if (null !== $publicName) {
            $user->setPublicProfile(true);
        }
        if ([] !== $roles) {
            $user->setTotpSecret('JBSWY3DPEHPK3PXP');
            $user->setTwoFaEnabled(true);
        }
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $c->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, 'securepass12345!'));
        $em->persist($user);
        $em->flush();
        if (null !== $area) {
            $em->persist(new ModeratorArea((int) $user->getId(), (int) $area->getId(), null));
            $em->flush();
        }

        return $user;
    }

    private function fresh(Submission $submission): Submission
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $sub = $em->find(Submission::class, (int) $submission->getId());
        self::assertInstanceOf(Submission::class, $sub);

        return $sub;
    }

    private function latestText(int $userId): Submission
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $sub = $em->getRepository(Submission::class)->findOneBy(['userId' => $userId, 'type' => SubmissionType::Text], ['id' => 'DESC']);
        self::assertInstanceOf(Submission::class, $sub, 'a Text submission was filed');

        return $sub;
    }

    /** @return list<int> */
    private function queueIds(User $curator): array
    {
        $c = static::getContainer();
        /** @var ModerationScopeProvider $scopes */
        $scopes = $c->get(ModerationScopeProvider::class);
        /** @var SubmissionQueue $queue */
        $queue = $c->get(SubmissionQueue::class);

        return array_map(static fn (array $r): int => $r['id'], $queue->pendingForMap($scopes->scopeFor($curator)));
    }

    private function moderation(): ModerationService
    {
        /** @var ModerationService $m */
        $m = static::getContainer()->get(ModerationService::class);

        return $m;
    }

    private function proposals(): PlaceTextProposals
    {
        /** @var PlaceTextProposals $p */
        $p = static::getContainer()->get(PlaceTextProposals::class);

        return $p;
    }
}
