<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Links;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The fetch behind a website verdict (docs/specs/website-liveness.md section
 * 1): one GET of the address as stored, at most 5 redirects, 8 seconds in
 * all, at most 64 KB of the body read, with our own User-Agent. No HEAD
 * first: enough hosts refuse HEAD to make it a second request for nothing.
 * The sweep hands over a batch and the requests are issued together,
 * CONCURRENCY at a time, then read one by one. The fetch leaves our server and carries nothing about a rider.
 *
 * @api
 */
final class WebsiteChecker
{
    public const string USER_AGENT = 'CyclingCommons website check (+https://cyclingcommons.org/about)';
    public const int MAX_REDIRECTS = 5;
    public const float TIMEOUT_S = 8.0;
    public const int MAX_BODY_BYTES = 65536;
    public const int CONCURRENCY = 20;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function check(string $url): WebsiteVerdict
    {
        return $this->checkMany([$url])[$url];
    }

    /**
     * @param list<string>                    $urls
     * @param (\Closure(int, int): void)|null $progress how many fetched addresses are answered and how many there are, before the first batch and after each
     *
     * @return array<string, WebsiteVerdict> url => verdict, every url answered
     */
    public function checkMany(array $urls, ?\Closure $progress = null): array
    {
        $out = [];
        $fetch = [];
        foreach (array_values(array_unique($urls)) as $url) {
            if (null === WebsiteVerdict::hostOf($url)) {
                $out[$url] = WebsiteVerdict::unfetchable();
                continue;
            }
            $fetch[] = $url;
        }
        $total = \count($fetch);
        $done = 0;
        if (null !== $progress && $total > 0) {
            $progress($done, $total);
        }
        // Every request in flight holds a socket; a provider's thousands of hosts at once exhaust the open-file limit.
        foreach (array_chunk($fetch, self::CONCURRENCY) as $batch) {
            $out += $this->checkBatch($batch);
            $done += \count($batch);
            if (null !== $progress) {
                $progress($done, $total);
            }
        }

        return $out;
    }

    /**
     * @param list<string> $urls
     *
     * @return array<string, WebsiteVerdict> url => verdict
     */
    private function checkBatch(array $urls): array
    {
        $out = [];
        /** @var array<string, ResponseInterface> $responses url => response */
        $responses = [];
        foreach ($urls as $url) {
            try {
                $responses[$url] = $this->http->request('GET', $url, [
                    'headers' => ['User-Agent' => self::USER_AGENT, 'Accept' => 'text/html,*/*;q=0.5'],
                    'max_redirects' => self::MAX_REDIRECTS,
                    'timeout' => self::TIMEOUT_S,
                    'max_duration' => self::TIMEOUT_S,
                    'user_data' => $url,
                ]);
            } catch (\Throwable $e) {
                $out[$url] = WebsiteVerdict::failed($e->getMessage());
            }
        }
        if ([] === $responses) {
            return $out;
        }
        // The requests are in flight together; reading them one by one only waits for each in turn.
        foreach ($responses as $url => $response) {
            try {
                $status = $response->getStatusCode();
                // false: an error status is an answer to judge, not an exception.
                $body = $response->getContent(false);
                $out[$url] = WebsiteVerdict::of($status, substr($body, 0, self::MAX_BODY_BYTES));
            } catch (\Throwable $e) {
                $this->logger->info('website check got no answer', ['url' => $url, 'error' => $e->getMessage()]);
                $out[$url] = WebsiteVerdict::failed($e->getMessage());
            }
        }

        return $out;
    }
}
