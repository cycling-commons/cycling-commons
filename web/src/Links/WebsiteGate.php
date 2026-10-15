<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Links;

use Psr\Clock\ClockInterface;

/**
 * The gate at intake (docs/specs/website-liveness.md section 2): the
 * verdict of an address right now, from the stored one when it is within
 * its period, else by one fetch that is then kept. The wizard, the API's
 * contributions and the provider ingest all ask here, so one site is judged
 * the same way wherever its address arrives, and a host is fetched once per
 * batch however many records share it.
 *
 * @api
 */
final class WebsiteGate
{
    public function __construct(
        private readonly WebsiteChecker $checker,
        private readonly WebsiteVerdictStore $store,
        private readonly ClockInterface $clock,
    ) {
    }

    /** One of the four verdict words for this address. */
    public function verdictOf(string $url): string
    {
        return $this->verdictsOf([$url])[$url];
    }

    /**
     * @param list<string> $urls
     *
     * @return array<string, string> url => verdict, every url answered
     */
    public function verdictsOf(array $urls): array
    {
        $now = $this->clock->now();
        $out = [];
        /** @var array<string, string> $toFetch host => the first address seen for it */
        $toFetch = [];
        foreach (array_values(array_unique($urls)) as $url) {
            $host = WebsiteVerdict::hostOf($url);
            if (null === $host) {
                $out[$url] = WebsiteVerdict::DEAD;
                continue;
            }
            $fresh = $this->store->fresh($host, $now);
            if (null !== $fresh) {
                $out[$url] = $fresh;
                continue;
            }
            $toFetch[$host] ??= $url;
        }
        if ([] === $toFetch) {
            return $out;
        }

        $verdicts = $this->checker->checkMany(array_values($toFetch));
        $byHost = [];
        foreach ($toFetch as $host => $url) {
            $verdict = $verdicts[$url] ?? WebsiteVerdict::failed('no answer');
            $this->store->record($host, $url, $verdict, $now);
            $byHost[$host] = $verdict->verdict;
        }
        foreach ($urls as $url) {
            if (!isset($out[$url])) {
                $out[$url] = $byHost[WebsiteVerdict::hostOf($url) ?? ''] ?? WebsiteVerdict::UNKNOWN;
            }
        }

        return $out;
    }
}
