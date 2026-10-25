<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Blog\BlogLocales;
use App\Blog\BlogRepository;
use App\Blog\BlogSideCall;
use App\Blog\Entity\BlogPost;
use App\Pagination\Pager;
use App\Routing\ActiveLocales;
use App\Routing\LocalePrefix;
use App\Routing\LocalizedPath;
use App\Twig\LocaleExtension;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The blog: an index, a post, and a feed.
 *
 * **Written in English and Dutch, readable in five.** A reader whose language
 * has no posts gets the English ones on their own URL, and the page says which
 * language it is showing. That is a smaller lie than a 404, and a much smaller
 * one than a blog nobody keeps up because every post costs five translations.
 *
 * **A draft is a 404, not a 403.** The difference matters: a 403 confirms that
 * a slug exists, which turns an unpublished URL into something worth guessing
 * at before it is ready.
 *
 * @see docs/specs/blog.md §5
 *
 * @api
 */
#[Route(LocalePrefix::PATHS)]
final class BlogController extends AbstractController
{
    private const int PER_PAGE = 10;

    public function __construct(private readonly BlogRepository $posts, private readonly ActiveLocales $activeLocales)
    {
    }

    #[Route(LocalizedPath::BLOG, name: 'blog', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $reading = $request->getLocale();
        $written = BlogLocales::resolve($reading);

        $pager = Pager::of(
            $request->query->getInt('page', 1),
            $this->posts->countPublished($written),
            self::PER_PAGE,
        );

        return $this->render('blog/index.html.twig', [
            'page_title' => 'blog.meta_title',
            'page_description' => 'blog.meta_description',
            'nav_active' => 'blog',
            'posts' => $this->posts->published($written, $pager['perPage'], $pager['offset']),
            'pager' => $pager,
            // Set only when the reader is being shown a language they did not
            // ask for, so the page can say so once rather than always.
            'fallback_from' => $written !== $reading ? $reading : null,
            'written_locale' => $written,
            // The languages the blog is actually written in, so the notice can
            // offer them rather than only naming them.
            'written_locales' => BlogLocales::WRITTEN,
        ]);
    }

    #[Route(LocalizedPath::BLOG_POST, name: 'blog_post', requirements: ['slug' => '[a-z0-9-]{1,160}'], methods: ['GET'])]
    public function post(string $slug, Request $request): Response
    {
        $written = BlogLocales::resolve($request->getLocale());

        // A reader following a link to a post in another language reads it,
        // not a 404 for a page that plainly exists: the reader's language
        // first, then every language the blog is written in.
        $post = null;
        foreach (array_unique([$written, BlogLocales::FALLBACK, ...BlogLocales::WRITTEN]) as $locale) {
            $post ??= $this->posts->publishedBySlug($slug, $locale);
        }
        if (null === $post) {
            throw $this->createNotFoundException();
        }

        // Each language the blog is written in, its own version of this post.
        $siblings = $this->posts->siblings($post);
        $versions = [$post->getLocale() => $post];
        foreach ($siblings as $sibling) {
            $versions[$sibling->getLocale()] ??= $sibling;
        }
        // A reader whose language has its own version reads that one: an
        // English address under /nl/ opens the Dutch post.
        if ($post->getLocale() !== $written && isset($versions[$written])) {
            return $this->redirectToRoute('blog_post', ['slug' => $versions[$written]->getSlug()]);
        }
        // The language menu and the hreflang lines name each language's own
        // version: the one that language reads (BlogLocales::resolve()), else
        // this post.
        $params = [];
        foreach ($this->activeLocales->all() as $locale) {
            $params[$locale] = ['slug' => ($versions[BlogLocales::resolve($locale)] ?? $post)->getSlug()];
        }
        $request->attributes->set(LocaleExtension::PARAMS_ATTRIBUTE, $params);

        return $this->render('blog/post.html.twig', [
            'page_title' => 'blog.meta_title',
            'page_description' => 'blog.meta_description',
            'nav_active' => 'blog',
            'post' => $post,
            'siblings' => $siblings,
            // The archive, beside the post. A reader who finished one is the
            // likeliest person on the site to read a second.
            'others' => $this->posts->othersThan($post, $written),
            // The invitation beside the post: the help post's address, or null
            // for "add what you know".
            'help_slug' => $this->helpSlug($post, $siblings, $written),
        ]);
    }

    /**
     * The slug of the help post in the reader's language, or null when the
     * invitation asks for contributions instead: the post asks for that, it
     * is the help post itself, or the help post is not published.
     *
     * @param list<BlogPost> $siblings
     */
    private function helpSlug(BlogPost $post, array $siblings, string $written): ?string
    {
        if (BlogSideCall::Contribute === $post->getSideCall()) {
            return null;
        }
        $help = $this->posts->publishedBySlug(BlogSideCall::HELP_SLUG, BlogLocales::FALLBACK);
        if (null === $help) {
            return null;
        }
        $versions = [$help, ...$this->posts->siblings($help)];
        foreach ($versions as $version) {
            if ($version->getId() === $post->getId()) {
                return null;
            }
        }
        foreach ($versions as $version) {
            if ($version->getLocale() === $written) {
                return $version->getSlug();
            }
        }

        return $help->getSlug();
    }

    /**
     * Atom, one per locale prefix like every page here: `/blog.atom` is the
     * English feed and `/nl/blog.atom` the Dutch one. A feed reader fetches
     * from its own server and often sends no `Accept-Language`, so the path
     * is the only reliable way to hand a Dutch subscriber the Dutch posts.
     */
    #[Route('/blog.atom', name: 'blog_atom', methods: ['GET'])]
    public function feed(Request $request): Response
    {
        $written = BlogLocales::resolve($request->getLocale());

        $response = $this->render('blog/feed.atom.twig', [
            'posts' => $this->posts->forFeed($written),
            'written_locale' => $written,
            'site' => $request->getSchemeAndHttpHost(),
        ]);
        $response->headers->set('Content-Type', 'application/atom+xml; charset=UTF-8');
        // A reader polls. Nothing here is personal and nothing changes between
        // visits, so it caches like the changelog's does.
        $response->setPublic();
        $response->setMaxAge(3600);

        return $response;
    }
}
