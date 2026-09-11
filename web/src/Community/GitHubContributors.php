<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Community;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The GitHub half of the spin-out contributor count (wiki/governance.md
 * commitment 3): who has had a pull request merged with an approving review.
 *
 * One search call answers the whole question, so the sync is a daily cron
 * rather than a deploy hook or a git parse: the server needs no .git, and
 * the answer is exactly the governance definition — merged means it landed,
 * review:approved means someone checked it. Keyed on the GitHub login, not
 * an email: GitHub hides most emails behind per-account noreplies, and one
 * person can hold two. The public profile email is fetched best-effort for
 * the logins that publish one, so the counting side (CommunityProgress) can
 * merge a GitHub identity with the same person's site account.
 *
 * The repo and token are the same GITHUB_REPO / GITHUB_TOKEN the bug desk
 * uses (App\Support\GitHubIssues): one repository named per environment,
 * staging reading BikeCodersLife/CyclingCommons and production reading
 * cycling-commons/cycling-commons.
 *
 * Staff logins are stored like anyone else — the table is the record, the
 * exclusion is the counting (CommunityProgress), so a login that stops being
 * staff retroactively counts without a re-sync.
 */
final class GitHubContributors
{
    /** GitHub caps a search at 1000 results; the loop stops well before. */
    private const MAX_PAGES = 10;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly Connection $db,
        #[Autowire('%env(GITHUB_REPO)%')]
        private readonly string $repo = '',
        #[Autowire('%env(GITHUB_TOKEN)%')]
        private readonly string $token = '',
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== $this->token
            && 1 === preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~', $this->repo);
    }

    /**
     * Pulls every merged, approved PR author and upserts them.
     *
     * @return array{contributors:int, added:int}
     */
    public function sync(): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('not_configured');
        }

        $before = (int) $this->db->executeQuery('SELECT COUNT(*) FROM github_contributor')->fetchOne();

        foreach ($this->fetchAuthors() as $login => $window) {
            $this->db->executeStatement(
                <<<'SQL'
                    INSERT INTO github_contributor (github_login, first_pr_at, last_pr_at)
                    VALUES (:login, :first, :last)
                    ON CONFLICT (github_login) DO UPDATE
                      SET first_pr_at = LEAST(github_contributor.first_pr_at, :first),
                          last_pr_at  = GREATEST(github_contributor.last_pr_at, :last)
                    SQL,
                ['login' => $login, 'first' => $window['first'], 'last' => $window['last']],
            );
        }

        $after = (int) $this->db->executeQuery('SELECT COUNT(*) FROM github_contributor')->fetchOne();

        $this->fillEmails();

        return ['contributors' => $after, 'added' => $after - $before];
    }

    /**
     * Best-effort: fetch the public profile email for every row that lacks
     * one, so the contributor count can merge GitHub logins with site
     * accounts. A hidden email stays NULL and is retried on the next run —
     * at this scale that is cheaper than storing a "we looked" marker.
     */
    private function fillEmails(): void
    {
        $pending = $this->db->fetchFirstColumn('SELECT github_login FROM github_contributor WHERE email IS NULL');
        foreach ($pending as $login) {
            $email = $this->fetchEmail((string) $login);
            if (null !== $email) {
                $this->db->executeStatement(
                    'UPDATE github_contributor SET email = :email WHERE github_login = :login',
                    ['email' => $email, 'login' => $login],
                );
            }
        }
    }

    private function fetchEmail(string $login): ?string
    {
        $response = $this->http->request('GET', 'https://api.github.com/users/'.rawurlencode($login), [
            'headers' => $this->githubHeaders(),
            'timeout' => 15,
            'max_duration' => 30,
        ]);
        // A 403/404 is rate limit or a deleted account; both retry tomorrow.
        if (200 !== $response->getStatusCode()) {
            return null;
        }
        $email = $response->toArray()['email'] ?? null;

        return \is_string($email) && '' !== trim($email) ? strtolower(trim($email)) : null;
    }

    /**
     * @return array{Accept: string, Authorization: string, X-GitHub-Api-Version: string, User-Agent: string}
     */
    private function githubHeaders(): array
    {
        return [
            'Accept' => 'application/vnd.github+json',
            'Authorization' => 'Bearer '.$this->token,
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent' => 'CyclingCommons-contributors',
        ];
    }

    /**
     * login => the window (first and last closed_at) of its merged,
     * approved PRs. Every page is folded into the same min/max, so the
     * stored arrival date is a person's true first merge however the API
     * happens to order the results.
     *
     * @return array<string, array{first:string, last:string}>
     */
    private function fetchAuthors(): array
    {
        $authors = [];
        for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
            $response = $this->http->request('GET', 'https://api.github.com/search/issues', [
                'headers' => $this->githubHeaders(),
                'query' => [
                    'q' => sprintf('repo:%s is:pr is:merged review:approved', $this->repo),
                    'per_page' => 100,
                    'page' => $page,
                ],
                'timeout' => 15,
                'max_duration' => 30,
            ]);
            if (200 !== $response->getStatusCode()) {
                throw new \RuntimeException('github_http_'.$response->getStatusCode());
            }
            $items = $response->toArray()['items'] ?? [];

            foreach ($items as $item) {
                $login = $item['user']['login'] ?? null;
                $merged = $item['closed_at'] ?? null;
                if (!\is_string($login) || !\is_string($merged)) {
                    continue;
                }
                if (!isset($authors[$login])) {
                    $authors[$login] = ['first' => $merged, 'last' => $merged];
                    continue;
                }
                $authors[$login]['first'] = min($authors[$login]['first'], $merged);
                $authors[$login]['last'] = max($authors[$login]['last'], $merged);
            }

            if (\count($items) < 100) {
                break;
            }
        }

        return $authors;
    }
}
