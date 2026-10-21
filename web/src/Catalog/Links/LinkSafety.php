<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog\Links;

/**
 * The one way the app asks whether a link is safe (catalog-data-model.md
 * §7): a stored verdict while it may still be used, else Google Safe
 * Browsing, whose answer is stored with how long it may be used (a match at
 * most its `cacheDuration` and never 30 minutes, a clean answer 10 minutes,
 * an unknown one not at all). So a reload, a resend or the next desk view
 * within that time asks Google nothing, and no link is called unsafe on
 * information older than the terms allow.
 *
 * Fails open: an outage or no key answers UNKNOWN, never an exception.
 *
 * @api
 */
final readonly class LinkSafety
{
    public function __construct(
        private SafeBrowsing $safeBrowsing,
        private LinkVerdictStore $store,
    ) {
    }

    /**
     * @param list<string> $urls
     *
     * @return array<string, string> url => SafeBrowsing::SAFE|UNSAFE|UNKNOWN, every url answered
     */
    public function verdicts(array $urls): array
    {
        $urls = array_values(array_unique(array_filter($urls, static fn (string $u): bool => '' !== trim($u))));
        if ([] === $urls) {
            return [];
        }
        try {
            $fresh = $this->store->freshVerdicts($urls);
            $ask = array_values(array_diff($urls, array_keys($fresh)));
            $answers = [] === $ask ? [] : $this->safeBrowsing->lookup($ask);
            if ([] !== $answers) {
                $this->store->record(
                    array_map(static fn (array $a): string => $a['verdict'], $answers),
                    array_map(static fn (array $a): int => $a['ttl'], $answers),
                );
            }
        } catch (\Throwable) {
            return array_fill_keys($urls, SafeBrowsing::UNKNOWN);
        }

        $out = [];
        foreach ($urls as $url) {
            $out[$url] = $fresh[$url] ?? $answers[$url]['verdict'] ?? SafeBrowsing::UNKNOWN;
        }

        return $out;
    }
}
