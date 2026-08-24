<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media\Commons;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The only class that talks to Wikimedia: a file's credit and licence, and a
 * Wikidata item's P18.
 *
 * Wikimedia asks every client to identify itself, and an anonymous batch is
 * rate-limited to 429 within a request or two. That is not theoretical: the
 * measurement run behind this feature failed exactly that way on 2026-08-24
 * before a User-Agent was set.
 *
 * @see docs/specs/coverage-provider.md §7
 *
 * @api
 */
final readonly class CommonsApi
{
    /**
     * Licences we may republish. The strings are Commons' own
     * `LicenseShortName`, which is also what `ccUrl()` in
     * web/assets/map/util.js already maps to a licence URL, so a name that
     * reaches the drawer is a name the drawer can link.
     */
    public const array FREE_LICENCES = [
        'CC0', 'Public domain', 'CC BY 4.0', 'CC BY 3.0', 'CC BY 2.0',
        'CC BY-SA 4.0', 'CC BY-SA 3.0', 'CC BY-SA 3.0 lu', 'CC BY-SA 2.5', 'CC BY-SA 2.0',
    ];

    public function __construct(
        private HttpClientInterface $http,
        #[Autowire('%env(APP_COMMONS_USER_AGENT)%')]
        private string $userAgent = '',
    ) {
    }

    /**
     * Credit, licence and a 1400px rendering URL, or null when the file is not
     * one we may republish.
     *
     * @return array{thumbUrl: string, credit: string, creditUser: ?string, license: string}|null
     *
     * @throws CommonsUnavailable
     */
    public function fileInfo(string $file): ?array
    {
        $data = $this->get('https://commons.wikimedia.org/w/api.php', [
            'action' => 'query',
            'format' => 'json',
            'formatversion' => '2',
            'titles' => 'File:'.$file,
            'prop' => 'imageinfo',
            'iiprop' => 'url|extmetadata',
            // Ask Commons to render the size we would produce anyway. Originals
            // include 200 MB TIFFs, and this bounds the download before
            // PhotoProcessor::MAX_BYTES ever has to refuse one.
            'iiurlwidth' => '1400',
        ]);

        $info = $data['query']['pages'][0]['imageinfo'][0] ?? null;
        if (!\is_array($info) || !\is_string($info['thumburl'] ?? null)) {
            return null;
        }

        $meta = \is_array($info['extmetadata'] ?? null) ? $info['extmetadata'] : [];
        $licenseRaw = $meta['LicenseShortName']['value'] ?? null;
        $license = \is_string($licenseRaw) ? trim($licenseRaw) : '';
        if (!\in_array($license, self::FREE_LICENCES, true)) {
            return null;   // fail closed: a licence we cannot name is one we do not republish
        }

        $artistRaw = $meta['Artist']['value'] ?? null;
        $artist = \is_string($artistRaw) ? $artistRaw : '';

        return [
            'thumbUrl' => $info['thumburl'],
            'credit' => self::plainCredit($artist),
            'creditUser' => self::commonsUser($artist),
            'license' => $license,
        ];
    }

    /**
     * The Commons file named by a Wikidata item's P18, or null when it has none.
     *
     * @throws CommonsUnavailable
     */
    public function wikidataImage(string $qid): ?string
    {
        $data = $this->get('https://www.wikidata.org/w/api.php', [
            'action' => 'wbgetclaims',
            'format' => 'json',
            'entity' => $qid,
            'property' => 'P18',
        ]);

        $value = $data['claims']['P18'][0]['mainsnak']['datavalue']['value'] ?? null;

        return \is_string($value) && '' !== trim($value) ? str_replace('_', ' ', trim($value)) : null;
    }

    /**
     * The rendering itself.
     *
     * This lives here and not in the handler because it is a request to
     * Wikimedia, and Wikimedia's rate limits are per client rather than per
     * endpoint. The handler used to fetch the bytes itself with no User-Agent
     * at all, so the metadata call identified us and the download that followed
     * it did not: upload.wikimedia.org answered 429 and the photo never
     * appeared. Found on the first real end-to-end run, 2026-08-25.
     *
     * @throws CommonsUnavailable
     */
    public function fetchThumb(string $url, int $maxBytes): string
    {
        try {
            $response = $this->http->request('GET', $url, [
                'headers' => ['User-Agent' => $this->userAgent],
                'timeout' => 30,
                'max_duration' => 60,
            ]);
            if (200 !== $response->getStatusCode()) {
                throw new CommonsUnavailable('http_'.$response->getStatusCode());
            }
            $bytes = $response->getContent();
        } catch (CommonsUnavailable $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new CommonsUnavailable($e->getMessage());
        }

        if (\strlen($bytes) > $maxBytes) {
            throw new CommonsUnavailable('too_large');
        }

        return $bytes;
    }

    /**
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     *
     * @throws CommonsUnavailable
     */
    private function get(string $url, array $query): array
    {
        try {
            $response = $this->http->request('GET', $url, [
                'query' => $query,
                'headers' => ['User-Agent' => $this->userAgent, 'Accept' => 'application/json'],
                'timeout' => 15,
                'max_duration' => 30,
            ]);
            if (200 !== $response->getStatusCode()) {
                throw new CommonsUnavailable('http_'.$response->getStatusCode());
            }

            return $response->toArray();
        } catch (CommonsUnavailable $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new CommonsUnavailable($e->getMessage());
        }
    }

    /** `<a ...>Jean-Pol GRANDMONT</a>` becomes `Jean-Pol GRANDMONT`. */
    private static function plainCredit(string $html): string
    {
        $text = trim(html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
        $text = trim(preg_replace('~\s+~u', ' ', $text) ?? $text);

        return '' === $text ? 'Wikimedia Commons' : mb_substr($text, 0, 255);
    }

    /** The Commons username behind the Artist link, for the credit URL. */
    private static function commonsUser(string $html): ?string
    {
        if (!preg_match('~/wiki/User:([^"\'#?<>]+)~', $html, $m)) {
            return null;
        }
        $user = trim(rawurldecode($m[1]));

        return '' === $user ? null : mb_substr($user, 0, 255);
    }
}
