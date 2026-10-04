<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Twig\SocialLinksExtension;
use PHPUnit\Framework\TestCase;

/**
 * The footer's social links: a handle goes onto its known host, Mastodon's
 * full address is used as it is (the profile's rel="me" check reads it), and
 * an empty channel does not render.
 */
final class SocialLinksExtensionTest extends TestCase
{
    public function testMastodonIsTheAddressAsGivenAndHandlesGoOntoTheirHost(): void
    {
        $links = $this->links(mastodon: 'https://mastodon.social/@cyclingcommons', instagram: '@cyclingcommons', strava: '2303650');

        self::assertSame([
            'instagram' => 'https://www.instagram.com/cyclingcommons',
            'mastodon' => 'https://mastodon.social/@cyclingcommons',
            'strava' => 'https://www.strava.com/clubs/2303650',
        ], $links);
    }

    public function testAMastodonValueThatIsNotAnHttpsAddressDoesNotRender(): void
    {
        self::assertSame([], $this->links(mastodon: '@cyclingcommons'));
        self::assertSame([], $this->links(mastodon: 'http://mastodon.social/@cyclingcommons'));
        self::assertSame([], $this->links(mastodon: 'javascript:alert(1)'));
    }

    /**
     * The channel's own full address is used as it is: a Facebook page known
     * only by its number has no handle, and a pasted YouTube link must not be
     * encoded into the handle slot.
     */
    public function testAFullAddressOnTheChannelsOwnHostIsUsedAsItIs(): void
    {
        $links = $this->links(
            youtube: 'https://www.youtube.com/@CyclingCommons',
            facebook: 'https://www.facebook.com/profile.php?id=61591531884599',
            bluesky: '@cyclingcommons.org',
        );

        self::assertSame([
            'youtube' => 'https://www.youtube.com/@CyclingCommons',
            'facebook' => 'https://www.facebook.com/profile.php?id=61591531884599',
            'bluesky' => 'https://bsky.app/profile/cyclingcommons.org',
        ], $links);
    }

    public function testAnAddressOnAnotherHostDoesNotRender(): void
    {
        self::assertSame([], $this->links(facebook: 'https://example.com/profile.php?id=1'));
        self::assertSame([], $this->links(youtube: 'http://www.youtube.com/@CyclingCommons'));
        self::assertSame([], $this->links(instagram: 'javascript://www.instagram.com/x'));
    }

    /** @return array<string, string> name => url */
    private function links(string $mastodon = '', string $instagram = '', string $strava = '', string $youtube = '', string $facebook = '', string $bluesky = ''): array
    {
        $ext = new SocialLinksExtension('', $youtube, $instagram, $facebook, $bluesky, $mastodon, $strava);
        $out = [];
        foreach ($ext->getGlobals()['social'] as $s) {
            $out[$s['name']] = $s['url'];
        }

        return $out;
    }
}
