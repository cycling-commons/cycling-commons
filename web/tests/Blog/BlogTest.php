<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Blog;

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
}
