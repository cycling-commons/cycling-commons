<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Blog\BlogLocales;
use App\Blog\BlogRepository;
use App\Pagination\Pager;
use App\Routing\LocalePrefix;
use App\Routing\LocalizedPath;
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

    public function __construct(private readonly BlogRepository $posts)
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

        $post = $this->posts->publishedBySlug($slug, $written);
        if (null === $post && BlogLocales::FALLBACK !== $written) {
            // A Dutch reader following a link to an English-only post should
            // read it, not meet a 404 for a page that plainly exists.
            $post = $this->posts->publishedBySlug($slug, BlogLocales::FALLBACK);
        }
        if (null === $post) {
            throw $this->createNotFoundException();
        }

        return $this->render('blog/post.html.twig', [
            'page_title' => 'blog.meta_title',
            'page_description' => 'blog.meta_description',
            'nav_active' => 'blog',
            'post' => $post,
            'siblings' => $this->posts->siblings($post),
            // The archive, beside the post. A reader who finished one is the
            // likeliest person on the site to read a second.
            'others' => $this->posts->othersThan($post, $written),
        ]);
    }

    /**
     * Atom, next to the changelog's and for the same reason.
     *
     * Not localised in the path: a feed reader subscribes once, and the
     * `Accept-Language` it sends is what decides which posts it gets.
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
        // visits, so it caches like the changelog's does. `Vary` on the
        // language, because that is what picks the posts.
        $response->setPublic();
        $response->setMaxAge(3600);
        $response->setVary('Accept-Language');

        return $response;
    }
}
