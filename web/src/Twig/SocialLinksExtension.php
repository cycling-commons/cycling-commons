<?php

// SPDX-License-Identifier: AGPL-3.0-only

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
 * **Every link carries `rel="me"`.** That is what lets a Mastodon profile
 * verify this site claims the account back.
 *
 * A global rather than a function, because the footer renders on every page and
 * a function would need threading through every controller's context.
 *
 * @see docs/specs/site-directory.md §5
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
        // A club, not a person: the handle is the club's number.
        ['strava', 'Strava', 'https://www.strava.com/clubs/%s'],
    ];

    public function __construct(
        #[Autowire('%cc.social.github%')] private readonly string $github,
        #[Autowire('%cc.social.youtube%')] private readonly string $youtube,
        #[Autowire('%cc.social.instagram%')] private readonly string $instagram,
        #[Autowire('%cc.social.facebook%')] private readonly string $facebook,
        #[Autowire('%cc.social.bluesky%')] private readonly string $bluesky,
        #[Autowire('%cc.social.mastodon%')] private readonly string $mastodon,
        #[Autowire('%cc.social.strava%')] private readonly string $strava,
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
            'strava' => $this->strava,
        ];

        $out = [];
        foreach (self::CHANNELS as [$name, $label, $template]) {
            $handle = trim($handles[$name]);
            if ('' === $handle) {
                continue;
            }

            // Mastodon is a full URL because the instance is part of the
            // address: it is used as it is, and only as an https URL, never
            // percent-encoded (an encoded address is a broken link, and the
            // profile's rel="me" check fails on it). The rest take a handle
            // on a known host, or the full address on that same host, used
            // as it is: a Facebook page known only by its number
            // (`profile.php?id=…`) has no handle to give. An address on any
            // other host does not render.
            if ('%s' === $template) {
                $url = self::httpsUrl($handle);
            } elseif (str_contains($handle, '://')) {
                $url = self::onHost($handle, $template);
            } else {
                $url = \sprintf($template, rawurlencode(ltrim($handle, '@')));
            }
            if (null === $url) {
                continue;
            }

            $out[] = ['name' => $name, 'label' => $label, 'url' => $url];
        }

        return ['social' => $out];
    }

    /** The value when it is an https URL on the template's host (with or without `www.`), else null. */
    private static function onHost(string $value, string $template): ?string
    {
        $url = self::httpsUrl($value);
        $bare = static fn (string $host): string => preg_replace('/^www\./', '', strtolower($host)) ?? '';

        return null !== $url && $bare((string) parse_url($url, \PHP_URL_HOST)) === $bare((string) parse_url($template, \PHP_URL_HOST)) ? $url : null;
    }

    /** The value when it is an https URL with a host, else null. */
    private static function httpsUrl(string $value): ?string
    {
        $parts = parse_url($value);
        if (false === $parts || 'https' !== strtolower($parts['scheme'] ?? '') || '' === ($parts['host'] ?? '')) {
            return null;
        }

        return $value;
    }
}
