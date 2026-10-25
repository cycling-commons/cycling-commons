<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Twig;

use App\Blog\BlogLocales;
use App\Blog\BlogRepository;
use App\Blog\Entity\BlogPost;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `blog_has_posts()`: whether the menu and the footer offer "Blog" at all.
 *
 * An empty blog is not linked: a menu item that opens "no posts yet" is a dead
 * end. Counted in the language the blog page would show this reader
 * ({@see BlogLocales::resolve()}), so a reader whose language has no posts of
 * its own still sees the link when the fallback language has some. Every page
 * asks, so the answer per language is kept in the app cache (Redis) for five
 * minutes, and dropped at once when a post is saved or deleted through
 * Doctrine; the five minutes cover a row changed any other way.
 *
 * @api
 */
#[AsEntityListener(event: Events::postPersist, method: 'forget', entity: BlogPost::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'forget', entity: BlogPost::class)]
#[AsEntityListener(event: Events::postRemove, method: 'forget', entity: BlogPost::class)]
final class BlogNavExtension extends AbstractExtension
{
    private const int TTL = 300;

    /** @var array<string, bool> */
    private array $known = [];

    public function __construct(
        private readonly BlogRepository $posts,
        private readonly RequestStack $requests,
        private readonly CacheInterface $cache,
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

        return $this->known[$locale] ??= $this->cache->get(self::key($locale), function (ItemInterface $item) use ($locale): bool {
            $item->expiresAfter(self::TTL);

            return $this->posts->countPublished($locale) > 0;
        });
    }

    /** A post was saved or deleted: every language is asked again. */
    public function forget(): void
    {
        foreach (BlogLocales::WRITTEN as $locale) {
            $this->cache->delete(self::key($locale));
        }
        $this->known = [];
    }

    private static function key(string $locale): string
    {
        return 'blog_nav.has_posts.'.$locale;
    }
}
