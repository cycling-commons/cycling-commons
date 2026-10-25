<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Blog;

use App\Blog\BlogIllustrations;
use App\Blog\BlogLocales;
use App\Blog\BlogStatus;
use App\Blog\Entity\BlogPost;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The blog (docs/specs/blog.md).
 *
 * The assertions that matter, in order:
 *
 * 1. **A draft is not readable, by anybody, by any route.** Not on the index,
 *    not by its own URL, not in the feed. It is a 404 rather than a 403,
 *    because a 403 confirms the slug exists and turns an unpublished URL into
 *    something worth guessing at before it is ready.
 * 2. **A reader whose language has no posts gets English**, not an empty page,
 *    and is told which language they are reading.
 * 3. **Publishing stamps the date once.** Withdrawing a post and putting it
 *    back must not move it to the top of the index and misdate it for
 *    everybody who already read it.
 */
final class BlogTest extends WebTestCase
{
    private function client(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function post(
        string $slug,
        string $locale = 'en',
        string $title = 'A map that belongs to the riders',
        bool $published = true,
        ?string $lede = 'A standfirst.',
    ): BlogPost {
        $post = new BlogPost($slug, $locale, $title, 'The **body** of it.');
        $post->setLede($lede);
        if ($published) {
            $post->setStatus(BlogStatus::Published);
        }
        $this->em()->persist($post);
        $this->em()->flush();

        return $post;
    }

    // -- a draft is not a page --------------------------------------------

    public function testADraftIsNotOnTheIndex(): void
    {
        $client = $this->client();
        $this->post('live-one', title: 'The published one');
        $this->post('draft-one', title: 'The unfinished one', published: false);

        $page = $client->request('GET', '/blog');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('The published one', $page->text());
        self::assertStringNotContainsString('The unfinished one', $page->text());
    }

    /** 404 and not 403: a 403 confirms the slug exists. */
    public function testADraftIsNotFoundByItsOwnUrl(): void
    {
        $client = $this->client();
        $this->post('draft-one', published: false);

        $client->request('GET', '/blog/draft-one');

        self::assertResponseStatusCodeSame(404);
    }

    public function testADraftIsNotInTheFeed(): void
    {
        $client = $this->client();
        $this->post('draft-one', title: 'The unfinished one', published: false);

        $client->request('GET', '/blog.atom');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('The unfinished one', (string) $client->getResponse()->getContent());
    }

    /**
     * A row that is Published with no date is a contradiction, and "not ready"
     * is the safe reading of one.
     */
    public function testAPublishedRowWithNoDateIsStillNotPublic(): void
    {
        $client = $this->client();
        $post = $this->post('half-set', title: 'Half set', published: false);

        // Force the contradiction the way only a hand-edited row could.
        $this->em()->getConnection()->executeStatement(
            "UPDATE blog_post SET status = 'published', published_at = NULL WHERE id = :id",
            ['id' => $post->getId()],
        );
        $this->em()->clear();

        $client->request('GET', '/blog/half-set');
        self::assertResponseStatusCodeSame(404);
    }

    // -- reading it -------------------------------------------------------

    public function testAPostRendersItsMarkdown(): void
    {
        $client = $this->client();
        $this->post('live-one');

        $page = $client->request('GET', '/blog/live-one');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('.bbody strong'), 'the markdown is rendered, not printed');
    }

    /** A bold line on its own is a section title; bold inside a sentence stays bold. */
    public function testABoldLineOnItsOwnIsASectionTitle(): void
    {
        $client = $this->client();
        $post = new BlogPost('with-titles', 'en', 'Titles', "Opening words.\n\n**Why we build it**\n\nA **bold** word in a sentence.\n\n**<b>typed</b> title**");
        $post->setStatus(BlogStatus::Published);
        $this->em()->persist($post);
        $this->em()->flush();

        $page = $client->request('GET', '/blog/with-titles');

        self::assertSame(['Why we build it', '<b>typed</b> title'], $page->filter('.bbody h2.bh')->each(static fn ($h): string => $h->text()), 'markup a writer types stays text, in a title too');
        self::assertSame('bold', $page->filter('.bbody p strong')->first()->text(), 'bold inside a sentence stays bold');
        self::assertCount(0, $page->filter('.bbody b'), 'no element from it');
        self::assertSame('Opening words.', $page->filter('.bbody > p')->first()->text(), 'the opening paragraph comes first');
    }

    /** [words](address) is a link: a site path in the same tab, https elsewhere in a new one, nothing else. */
    public function testALinkIsARealLinkAndOnlyASafeOne(): void
    {
        $client = $this->client();
        $body = "Start on [the map](/map), under *Plan a ride*.\n\n"
            ."Read [the licence](https://opendatacommons.org/licenses/odbl/1-0/) and [our terms](https://cyclingcommons.org/licenses).\n\n"
            ."Not these: [bad](javascript:alert(1)), [other](//evil.test/x), [quote](/x\"onclick=\"alert(1)), [plain](http://example.test).\n\n"
            .'Typed as code: `[the map](/map)`';
        $post = new BlogPost('with-links', 'en', 'Links', $body);
        $post->setStatus(BlogStatus::Published);
        $this->em()->persist($post);
        $this->em()->flush();

        $page = $client->request('GET', '/blog/with-links');

        self::assertSame([
            ['the map', '/map', null, null],
            ['the licence', 'https://opendatacommons.org/licenses/odbl/1-0/', '_blank', 'noopener'],
            ['our terms', 'https://cyclingcommons.org/licenses', null, null],
        ], $page->filter('.bbody a')->each(static fn ($a): array => [$a->text(), $a->attr('href'), $a->attr('target'), $a->attr('rel')]));
        self::assertStringContainsString('[quote](/x"onclick="alert(1))', $page->filter('.bbody')->text(), 'a typed quote never reaches an address');
        self::assertStringContainsString('[bad](javascript:alert(1))', $page->filter('.bbody')->text(), 'any other scheme stays text');
        self::assertStringContainsString('[plain](http://example.test)', $page->filter('.bbody')->text());
        self::assertSame('[the map](/map)', $page->filter('.bbody code')->text(), 'code stays what a reader types');
    }

    /** One image per post, first on the post and beside it on the index; a drawing is hidden from screen readers. */
    public function testAPostOpensWithItsOneDrawing(): void
    {
        $client = $this->client();
        $post = new BlogPost('with-drawing', 'en', 'Drawn', 'Words.');
        $post->setIllustration('plan');
        $post->setStatus(BlogStatus::Published);
        $plain = new BlogPost('without-drawing', 'en', 'Plain', 'Words.');
        $plain->setStatus(BlogStatus::Published);
        $this->em()->persist($post);
        $this->em()->persist($plain);
        $this->em()->flush();

        $page = $client->request('GET', '/blog/with-drawing');

        self::assertCount(1, $page->filter('figure.bill svg'));
        self::assertSame('true', $page->filter('figure.bill')->attr('aria-hidden'));
        self::assertSame('bill', $page->filter('.blogpage > *')->first()->attr('class'), 'the image comes first, always in the same place');
        self::assertCount(0, $client->request('GET', '/blog/without-drawing')->filter('figure.bill'));
        $index = $client->request('GET', '/blog');
        self::assertCount(1, $index->filter('.bp--ill figure.bthumb svg'), 'the index shows it beside the title');
    }

    /** A name that is no drawing or image is stored as none, so it never reaches a template path. */
    public function testAnUnknownIllustrationIsNone(): void
    {
        $post = new BlogPost('odd', 'en', 'Odd', 'Words.');
        $post->setIllustration('../../base');
        self::assertNull($post->getIllustration());
        $post->setIllustration('vote');
        self::assertSame('vote', $post->getIllustration());
        $post->setIllustration(null);
        self::assertNull($post->getIllustration());
    }

    /** Every drawing in the list has its template, and every image file exists with alt text in each language. */
    public function testEveryIllustrationRenders(): void
    {
        $client = $this->client();
        foreach (array_keys(BlogIllustrations::NAMES + BlogIllustrations::images()) as $i => $name) {
            $post = new BlogPost('ill-'.$i, 'en', 'Ill '.$i, 'Words.');
            $post->setIllustration($name);
            $post->setStatus(BlogStatus::Published);
            $this->em()->persist($post);
            $this->em()->flush();
            $page = $client->request('GET', '/blog/ill-'.$i);
            self::assertResponseIsSuccessful($name);
            self::assertCount(1, $page->filter('figure.bill svg, figure.bill img[alt]:not([alt=""])'), $name);
        }
    }

    /** The same restricted markdown the bug desk uses: no script, ever. */
    public function testAPostCannotCarryMarkup(): void
    {
        $client = $this->client();
        $post = new BlogPost('nasty', 'en', 'Nasty', 'Before <script>alert(1)</script> after.');
        $post->setStatus(BlogStatus::Published);
        $this->em()->persist($post);
        $this->em()->flush();

        $html = (string) $client->request('GET', '/blog/nasty')->html();

        self::assertStringNotContainsString('<script>alert(1)', $html);
        self::assertStringContainsString('after.', $html);
    }

    /** A post may be stored at BODY_MAX, and all of it is published. */
    public function testALongPostIsPublishedToItsLastWord(): void
    {
        $client = $this->client();
        $body = str_repeat('word ', 2600).'LASTWORD';
        self::assertGreaterThan(8000, \strlen($body), 'longer than a bug report may render');
        $post = new BlogPost('long-one', 'en', 'Long', $body);
        $post->setStatus(BlogStatus::Published);
        $this->em()->persist($post);
        $this->em()->flush();

        $client->request('GET', '/blog/long-one');

        self::assertSelectorTextContains('.bbody', 'LASTWORD');
    }

    // -- languages --------------------------------------------------------

    public function testDutchReadersSeeDutchPosts(): void
    {
        $client = $this->client();
        $this->post('english-one', 'en', 'The English one');
        $this->post('dutch-one', 'nl', 'De Nederlandse');

        $page = $client->request('GET', '/nl/blog');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('De Nederlandse', $page->text());
        self::assertStringNotContainsString('The English one', $page->text());
    }

    /**
     * A French reader gets the English posts, and is told so.
     *
     * The alternative was five translations per post, which is the cost that
     * quietly stops anybody writing the second one.
     */
    public function testAnUnwrittenLanguageFallsBackToEnglishAndSaysSo(): void
    {
        $client = $this->client();
        $this->post('english-one', 'en', 'The English one');

        $page = $client->request('GET', '/fr/blog');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('The English one', $page->text());
        self::assertCount(1, $page->filter('.blang'), 'the language notice is shown once');
    }

    public function testTheNoticeIsNotShownToAReaderInAWrittenLanguage(): void
    {
        $client = $this->client();
        $this->post('dutch-one', 'nl', 'De Nederlandse');

        $page = $client->request('GET', '/nl/blog');

        self::assertCount(0, $page->filter('.blang'));
    }

    public function testTheWrittenLocalesAreEnglishAndDutch(): void
    {
        self::assertSame(['en', 'nl'], BlogLocales::WRITTEN);
        self::assertSame('en', BlogLocales::resolve('fr'));
        self::assertSame('nl', BlogLocales::resolve('nl'));
    }

    /** A Dutch reader following a link to an English-only post should read it. */
    public function testAnEnglishOnlyPostIsReadableOnADutchUrl(): void
    {
        $client = $this->client();
        $this->post('english-only', 'en', 'Only in English');

        $page = $client->request('GET', '/nl/blog/english-only');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Only in English', $page->text());
    }

    /**
     * A post with a Dutch version: the language menu and the hreflang lines
     * name each language's own version, and an address of the other version
     * leads the reader to theirs.
     */
    public function testThePostInAnotherLanguageIsItsOwnVersion(): void
    {
        $client = $this->client();
        $en = $this->post('cc-is-online', 'en', 'Online now');
        $nl = $this->post('cc-staat-online', 'nl', 'Nu online');
        $nl->setTranslationOf($en->getId());
        $this->em()->flush();

        $page = $client->request('GET', '/blog/cc-is-online');
        self::assertResponseIsSuccessful();
        self::assertStringEndsWith('/nl/blog/cc-staat-online', (string) $page->filter('link[rel=alternate][hreflang=nl]')->attr('href'));
        self::assertStringEndsWith('/blog/cc-is-online', (string) $page->filter('link[rel=alternate][hreflang=en]')->attr('href'));
        self::assertSame('/i18n/nl?to=/nl/blog/cc-staat-online', $page->filter('#cc-lang-dd a[href^="/i18n/nl"]')->attr('href'), 'the language menu opens the Dutch version');

        $client->request('GET', '/nl/blog/cc-is-online');
        self::assertResponseRedirects('/nl/blog/cc-staat-online', null, 'a Dutch reader of the English address reads the Dutch post');
        $client->request('GET', '/blog/cc-staat-online');
        self::assertResponseRedirects('/blog/cc-is-online', null, 'and an English reader of the Dutch address the English one');
    }

    // -- publishing -------------------------------------------------------

    /**
     * The date is stamped once and kept.
     *
     * Otherwise pulling a post and putting it back would jump it to the top of
     * the index and misdate it for everybody who already read it.
     */
    public function testWithdrawingAndRepublishingKeepsTheOriginalDate(): void
    {
        self::bootKernel();
        $post = new BlogPost('dated', 'en', 'Dated', 'Body.');

        $first = new \DateTimeImmutable('2026-01-05 09:00:00');
        $post->setStatus(BlogStatus::Published, $first);
        self::assertEquals($first, $post->getPublishedAt());

        $post->setStatus(BlogStatus::Draft);
        self::assertEquals($first, $post->getPublishedAt(), 'withdrawing keeps it');

        $post->setStatus(BlogStatus::Published, new \DateTimeImmutable('2026-06-01 09:00:00'));
        self::assertEquals($first, $post->getPublishedAt(), 'republishing does not restamp');
    }

    public function testSlugsAreMadeReadable(): void
    {
        self::assertSame('a-map-that-belongs-to-riders', BlogPost::slugify('A map that belongs to riders'));
        self::assertSame('ou-nous-trouver', BlogPost::slugify('Où nous trouver'));
        self::assertSame('een-kaart', BlogPost::slugify('  Een kaart!  '));
    }

    // -- the feed ---------------------------------------------------------

    public function testTheFeedIsAtomAndCarriesThePosts(): void
    {
        $client = $this->client();
        $this->post('live-one', title: 'The published one');

        $client->request('GET', '/blog.atom');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/atom+xml; charset=UTF-8');

        $xml = (string) $client->getResponse()->getContent();
        self::assertStringStartsWith('<?xml', $xml, 'the declaration must be the first byte');
        self::assertStringContainsString('The published one', $xml);

        $doc = new \DOMDocument();
        self::assertTrue($doc->loadXML($xml), 'the feed must be well formed XML');
    }

    public function testTheIndexAdvertisesTheFeed(): void
    {
        $client = $this->client();
        $page = $client->request('GET', '/blog');

        self::assertCount(1, $page->filter('link[type="application/atom+xml"]'));
    }

    /**
     * One feed per locale prefix (blog.md §5). A feed reader often sends no
     * Accept-Language, so the path picks the posts, never the header.
     */
    public function testEachLocaleHasItsOwnFeed(): void
    {
        $client = $this->client();
        $this->post('english-feed', 'en', 'The English one');
        $this->post('dutch-feed', 'nl', 'De Nederlandse');

        $client->request('GET', '/nl/blog.atom', server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);
        $xml = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('xml:lang="nl"', $xml);
        self::assertStringContainsString('De Nederlandse', $xml);
        self::assertStringNotContainsString('The English one', $xml);
        self::assertStringNotContainsString('Accept-Language', (string) $client->getResponse()->headers->get('Vary'));

        $client->request('GET', '/blog.atom', server: ['HTTP_ACCEPT_LANGUAGE' => 'nl']);
        $xml = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('xml:lang="en"', $xml);
        self::assertStringContainsString('The English one', $xml);
    }

    public function testTheDutchIndexAdvertisesTheDutchFeed(): void
    {
        $client = $this->client();
        $page = $client->request('GET', '/nl/blog');

        self::assertSame('/nl/blog.atom', $page->filter('link[type="application/atom+xml"]')->attr('href'));
    }
}
