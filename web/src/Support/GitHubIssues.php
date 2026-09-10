<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Support;

use App\Support\Entity\BugReport;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Opens an issue on the public repository for a bug a curator made public.
 *
 * A link, not a sync (owner 2026-09-08: "only admins are allowed to put site
 * issues to github"). Two systems of record would drift, and the desk holds
 * what must never reach a public repository: the reporter's address, the
 * internal note, the page they stood on. So exactly two fields travel, the
 * public title and the public body, and the number that comes back is stored
 * on the row. Closing the issue stays a git act.
 *
 * @see docs/specs/contact-and-support.md §9
 *
 * @api
 */
final readonly class GitHubIssues
{
    public function __construct(
        private HttpClientInterface $http,
        #[Autowire('%env(GITHUB_REPO)%')]
        private string $repo = '',
        #[Autowire('%env(GITHUB_TOKEN)%')]
        private string $token = '',
        #[Autowire('%env(APP_SITE_URL)%')]
        private string $siteUrl = '',
    ) {
    }

    /** Both settings present, and the repository named as owner/name. */
    public function isConfigured(): bool
    {
        return '' !== $this->token && 1 === preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~', $this->repo);
    }

    /** owner/name, for the public list's link. Empty when not configured. */
    public function repo(): string
    {
        return $this->isConfigured() ? $this->repo : '';
    }

    /** The web address of an issue, for the desk and the public list. */
    public function issueUrl(int $number): string
    {
        return sprintf('https://github.com/%s/issues/%d', $this->repo, $number);
    }

    /**
     * Opens the issue and returns its number.
     *
     * @throws \RuntimeException when the desk is not configured, the bug is not public, or GitHub refused
     */
    public function open(BugReport $report): int
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('not_configured');
        }
        $title = trim($report->getPublicTitle());
        if (!$report->isPublic() || '' === $title) {
            throw new \RuntimeException('not_public');
        }
        $body = trim((string) $report->getPublicBody());
        $footer = sprintf(
            "\n\n---\nFiled from the Cycling Commons bugs desk, where it is public: %s/known-issues (desk #%d, %s, %s).",
            rtrim($this->siteUrl, '/'),
            (int) $report->getId(),
            $report->getSeverity()->value,
            $report->getArea()->value,
        );

        try {
            $response = $this->http->request('POST', sprintf('https://api.github.com/repos/%s/issues', $this->repo), [
                'headers' => [
                    'Accept' => 'application/vnd.github+json',
                    'Authorization' => 'Bearer '.$this->token,
                    'X-GitHub-Api-Version' => '2022-11-28',
                    'User-Agent' => 'CyclingCommons-desk',
                ],
                'json' => ['title' => mb_substr($title, 0, 250), 'body' => $body.$footer, 'labels' => ['from-desk']],
                'timeout' => 15,
                'max_duration' => 30,
            ]);
            $status = $response->getStatusCode();
            if (201 !== $status) {
                throw new \RuntimeException('github_http_'.$status);
            }
            $data = $response->toArray();
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException('github: '.$e->getMessage(), 0, $e);
        }
        $number = $data['number'] ?? null;
        if (!\is_int($number) || $number <= 0) {
            throw new \RuntimeException('github_no_number');
        }

        return $number;
    }
}
