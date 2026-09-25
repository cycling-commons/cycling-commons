<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Twig;

use App\Blog\BlogLocales;
use App\Blog\BlogRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `blog_has_posts()`: whether the menu and the footer offer "Blog" at all.
 *
 * An empty blog is not linked: a menu item that opens "no posts yet" is a dead
 * end. Counted in the language the blog page would show this reader
 * ({@see BlogLocales::resolve()}), so a reader whose language has no posts of
 * its own still sees the link when the fallback language has some. One query
 * per request, however many templates ask.
 *
 * @api
 */
final class BlogNavExtension extends AbstractExtension
{
    /** @var array<string, bool> */
    private array $known = [];

    public function __construct(
        private readonly BlogRepository $posts,
        private readonly RequestStack $requests,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [new TwigFunction('blog_has_posts', $this->hasPosts(...))];
    }

    public function hasPosts(): bool
    {
        $locale = BlogLocales::resolve($this->requests->getCurrentRequest()?->getLocale() ?? 'en');

        return $this->known[$locale] ??= $this->posts->countPublished($locale) > 0;
    }
}
