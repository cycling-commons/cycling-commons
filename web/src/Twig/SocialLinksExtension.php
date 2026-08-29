<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Where to find the Commons, for the footer's icon row.
 *
 * **Configured, not hard-coded.** A channel with no account simply does not
 * render, so a fresh deployment shows the channels it actually has rather than
 * a row of links to somebody else's 404. That is the same rule the legal
 * identity block follows, and for the same reason.
 *
 * **GitHub is here rather than in the link row.** It is a place we are, not a
 * page of this site, and sitting between "Governance" and "Get involved" it
 * read as the latter.
 *
 * **Bluesky rather than X.** Not a slot left empty out of neglect: an atlas
 * built on open data has no business sending riders somewhere that is not, and
 * the AT Protocol is. Mastodon renders too when it is configured, which is why
 * every link carries `rel="me"`: that is what lets a Mastodon profile verify
 * this site claims the account back.
 *
 * A global rather than a function, because the footer renders on every page and
 * a function would need threading through every controller's context.
 *
 * @see docs/specs/contact-and-support.md §19
 *
 * @api
 */
final class SocialLinksExtension extends AbstractExtension implements GlobalsInterface
{
    /**
     * Name, label, and the URL template its handle goes into.
     *
     * The order is the order they render: the two we point developers and
     * riders at first, then the rest.
     *
     * @var list<array{string, string, string}>
     */
    private const array CHANNELS = [
        ['github', 'GitHub', 'https://github.com/%s'],
        ['youtube', 'YouTube', 'https://www.youtube.com/@%s'],
        ['instagram', 'Instagram', 'https://www.instagram.com/%s'],
        ['facebook', 'Facebook', 'https://www.facebook.com/%s'],
        ['bluesky', 'Bluesky', 'https://bsky.app/profile/%s'],
        ['mastodon', 'Mastodon', '%s'],
    ];

    public function __construct(
        #[Autowire('%cc.social.github%')] private readonly string $github,
        #[Autowire('%cc.social.youtube%')] private readonly string $youtube,
        #[Autowire('%cc.social.instagram%')] private readonly string $instagram,
        #[Autowire('%cc.social.facebook%')] private readonly string $facebook,
        #[Autowire('%cc.social.bluesky%')] private readonly string $bluesky,
        #[Autowire('%cc.social.mastodon%')] private readonly string $mastodon,
    ) {
    }

    /**
     * @return array{social: list<array{name: string, label: string, url: string}>}
     */
    #[\Override]
    public function getGlobals(): array
    {
        $handles = [
            'github' => $this->github,
            'youtube' => $this->youtube,
            'instagram' => $this->instagram,
            'facebook' => $this->facebook,
            'bluesky' => $this->bluesky,
            'mastodon' => $this->mastodon,
        ];

        $out = [];
        foreach (self::CHANNELS as [$name, $label, $template]) {
            $handle = trim($handles[$name]);
            if ('' === $handle) {
                continue;
            }

            $out[] = [
                'name' => $name,
                'label' => $label,
                // Mastodon is a full URL because the instance is part of the
                // address; the rest are handles on a known host.
                'url' => \sprintf($template, rawurlencode(ltrim($handle, '@'))),
            ];
        }

        return ['social' => $out];
    }
}
