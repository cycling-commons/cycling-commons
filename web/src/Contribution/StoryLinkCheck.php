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
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

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
 * with the addresses and what was wrong with them, and `earlier` for the
 * ones a curator had already confirmed.
 *
 * A curator confirms a link once: on an approved text, their confirmation
 * covers that address in every later text, in any language, while it shows
 * the same problem (`confirmed`, open()). An unsafe link is confirmed every
 * time, and so is one whose problem changed.
 *
 * @api
 */
final readonly class StoryLinkCheck
{
    public function __construct(
        private WebsiteGate $websites,
        private LinkSafety $linkSafety,
        private Connection $db,
    ) {
    }

    /**
     * What the two checks say about each address: `refused` names what is
     * wrong (`dead`, `parked`, `unsafe`), else null; `confirmed` is true when
     * a curator already confirmed it with the problem it has now. The form
     * shows this as links appear in the box.
     *
     * @param list<string> $urls
     *
     * @return array<string, array{site: string, safety: string, refused: ?string, confirmed: bool}>
     */
    public function checkLinks(array $urls): array
    {
        $urls = array_values(array_unique($urls));
        if ([] === $urls) {
            return [];
        }
        $sites = $this->websites->verdictsOf($urls);
        $safety = $this->linkSafety->verdicts($urls);
        $before = $this->confirmedBefore($urls);

        $out = [];
        foreach ($urls as $url) {
            $site = $sites[$url] ?? WebsiteVerdict::UNKNOWN;
            $safe = $safety[$url] ?? SafeBrowsing::UNKNOWN;
            $refused = match (true) {
                WebsiteVerdict::DEAD === $site => 'dead',
                WebsiteVerdict::PARKED === $site => 'parked',
                SafeBrowsing::UNSAFE === $safe => 'unsafe',
                default => null,
            };
            $problem = self::problemOf($refused, $site, $safe);
            $out[$url] = [
                'site' => $site,
                'safety' => $safe,
                'refused' => $refused,
                'confirmed' => null !== $problem && self::coveredBefore($problem, $before[$url] ?? []),
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
            $problem = self::problemOf($verdict['refused'], $verdict['site'], $verdict['safety']);
            if (null !== $problem) {
                $out[$url] = $problem;
            }
        }

        return $out;
    }

    /**
     * Of a text's problem links, the ones still to confirm by hand: those a
     * curator has not confirmed before with the same problem, and every
     * unsafe one.
     *
     * @param array<string, string> $problems url => problem
     *
     * @return array<string, string>
     */
    public function open(array $problems): array
    {
        $before = $this->confirmedBefore(array_map('strval', array_keys($problems)));
        $open = [];
        foreach ($problems as $url => $problem) {
            if (!self::coveredBefore($problem, $before[$url] ?? [])) {
                $open[$url] = $problem;
            }
        }

        return $open;
    }

    /**
     * A confirmation to keep on the payload: who, when, which links with
     * what, and which of them a curator had already confirmed (`earlier`).
     *
     * @param array<string, string> $problems
     * @param list<string>          $earlier
     *
     * @return array{by: int, at: string, problems: array<string, string>, earlier?: list<string>}
     */
    public static function confirmation(User $by, array $problems, array $earlier = []): array
    {
        return [
            'by' => (int) $by->getId(),
            'at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'problems' => $problems,
        ] + ([] === $earlier ? [] : ['earlier' => $earlier]);
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

    /** What a verdict asks the writer to confirm: what is wrong, `unknown` when a check could not answer, else null. */
    private static function problemOf(?string $refused, string $site, string $safety): ?string
    {
        return $refused ?? (WebsiteVerdict::UNKNOWN === $site || SafeBrowsing::UNKNOWN === $safety ? 'unknown' : null);
    }

    /** @param list<string> $seen the problems a curator confirmed this address with */
    private static function coveredBefore(string $problem, array $seen): bool
    {
        return 'unsafe' !== $problem && \in_array($problem, $seen, true);
    }

    /**
     * The problems a curator confirmed each address with, on a text that is
     * approved now (`_links.approved`): a tick on a text still waiting, or
     * on one rejected or taken back, confirms nothing for later texts.
     *
     * @param list<string> $urls
     *
     * @return array<string, list<string>>
     */
    private function confirmedBefore(array $urls): array
    {
        if ([] === $urls) {
            return [];
        }
        $rows = $this->db->fetchAllAssociative(
            "SELECT p.key AS url, p.value AS problem
             FROM submission s
             CROSS JOIN LATERAL jsonb_each_text(CASE WHEN jsonb_typeof(s.payload->'_links'->'approved'->'problems') = 'object'
                 THEN s.payload->'_links'->'approved'->'problems' ELSE '{}'::jsonb END) p
             WHERE s.type = 'text' AND s.status = 'approved' AND p.key IN (:urls)",
            ['urls' => $urls],
            ['urls' => ArrayParameterType::STRING],
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['url']][] = (string) $row['problem'];
        }

        return $out;
    }
}
