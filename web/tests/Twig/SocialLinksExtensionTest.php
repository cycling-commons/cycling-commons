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

    /** @return array<string, string> name => url */
    private function links(string $mastodon = '', string $instagram = '', string $strava = ''): array
    {
        $ext = new SocialLinksExtension('', '', $instagram, '', '', $mastodon, $strava);
        $out = [];
        foreach ($ext->getGlobals()['social'] as $s) {
            $out[$s['name']] = $s['url'];
        }

        return $out;
    }
}
