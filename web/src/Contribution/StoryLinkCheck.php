<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Contribution;

use App\Catalog\Links\LinkSafety;
use App\Catalog\Links\SafeBrowsing;
use App\Catalog\RegionStory;
use App\Entity\User;
use App\Links\WebsiteGate;
use App\Links\WebsiteVerdict;

/**
 * The links of a region's history text, checked (moderation-and-contribution.md
 * §3.1c): is there a site (the website gate) and does Google Safe Browsing
 * name it (LinkSafety). A link that is not plainly fine (dead, for sale,
 * unsafe, or a check that could not answer) is never refused outright: the
 * writer, and the curator who approves it, confirm by hand that they opened
 * it and it is right. So an outage or a site that turns scripts away never
 * blocks a text, and nothing unclear goes live unseen.
 *
 * The confirmations ride the submission's payload under `_links`: `writer`
 * when it was sent, `approved` for the approval, each `{by, at, problems}`
 * with the addresses and what was wrong with them.
 *
 * @api
 */
final readonly class StoryLinkCheck
{
    public function __construct(
        private WebsiteGate $websites,
        private LinkSafety $linkSafety,
    ) {
    }

    /**
     * What the two checks say about each address: `refused` names what is
     * wrong (`dead`, `parked`, `unsafe`), else null. The form shows this as
     * links appear in the box.
     *
     * @param list<string> $urls
     *
     * @return array<string, array{site: string, safety: string, refused: ?string}>
     */
    public function checkLinks(array $urls): array
    {
        $urls = array_values(array_unique($urls));
        if ([] === $urls) {
            return [];
        }
        $sites = $this->websites->verdictsOf($urls);
        $safety = $this->linkSafety->verdicts($urls);

        $out = [];
        foreach ($urls as $url) {
            $site = $sites[$url] ?? WebsiteVerdict::UNKNOWN;
            $safe = $safety[$url] ?? SafeBrowsing::UNKNOWN;
            $out[$url] = [
                'site' => $site,
                'safety' => $safe,
                'refused' => match (true) {
                    WebsiteVerdict::DEAD === $site => 'dead',
                    WebsiteVerdict::PARKED === $site => 'parked',
                    SafeBrowsing::UNSAFE === $safe => 'unsafe',
                    default => null,
                },
            ];
        }

        return $out;
    }

    /**
     * The links of a text that are not plainly fine, with what is wrong:
     * `dead`, `parked`, `unsafe`, or `unknown` when a check could not
     * answer. Empty when every link checked out.
     *
     * @return array<string, string> url => problem
     */
    public function problems(string $text): array
    {
        $out = [];
        foreach ($this->checkLinks(array_column(RegionStory::links($text), 'href')) as $url => $verdict) {
            $problem = $verdict['refused']
                ?? (WebsiteVerdict::UNKNOWN === $verdict['site'] || SafeBrowsing::UNKNOWN === $verdict['safety'] ? 'unknown' : null);
            if (null !== $problem) {
                $out[$url] = $problem;
            }
        }

        return $out;
    }

    /**
     * A confirmation to keep on the payload: who, when, and which links with what.
     *
     * @param array<string, string> $problems
     *
     * @return array{by: int, at: string, problems: array<string, string>}
     */
    public static function confirmation(User $by, array $problems): array
    {
        return [
            'by' => (int) $by->getId(),
            'at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'problems' => $problems,
        ];
    }

    /**
     * Whether a confirmation on the payload covers every problem link: each
     * was confirmed. A link that became a problem after it was confirmed is
     * not covered.
     *
     * @param array<string, mixed>  $payload
     * @param array<string, string> $problems
     */
    public static function covers(array $payload, string $which, array $problems): bool
    {
        $confirmed = $payload['_links'][$which]['problems'] ?? null;

        return [] === array_diff(array_keys($problems), \is_array($confirmed) ? array_keys($confirmed) : []);
    }
}
