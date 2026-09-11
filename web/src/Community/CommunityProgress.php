<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Community;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The two counters the spin-out commitment is paid in (wiki/governance.md,
 * commitment 3): external contributors with a reviewed contribution to the
 * project, and rider-backed data points.
 *
 * Both are deliberately checkable, which shapes where each number comes
 * from. Approved curatorships, approved translations, curator posts and
 * published blog posts live in this database, so they are counted live.
 * Code lives on GitHub,
 * and `app:community:sync-contributors` — a daily cron, not a deploy hook —
 * keeps its half in the github_contributor table: merged, approved PR
 * authors keyed on login, which is exactly the governance definition and
 * needs no .git on the server. Staff accounts and staff logins are excluded
 * from both sides, however much they do: the trigger measures the community
 * arriving, not the stewards working.
 *
 * The two halves merge in PHP on a small set of identities: the sync stores
 * each login's public GitHub email when GitHub publishes one, so the
 * curator who also ships code is one person, counted once; a login without
 * a published email is keyed by the login itself. No namespacing is needed:
 * GitHub logins cannot contain "@", so a login can never collide with an
 * email.
 *
 * @see docs/specs/moderation-and-contribution.md
 */
final class CommunityProgress
{
    /**
     * @param list<string> $staffEmails steward accounts, never counted
     * @param list<string> $staffLogins steward GitHub logins, never counted
     */
    public function __construct(
        private readonly Connection $db,
        #[Autowire('%app.community_progress.target_contributors%')]
        private readonly int $targetContributors,
        #[Autowire('%app.community_progress.target_data_points%')]
        private readonly int $targetDataPoints,
        #[Autowire('%app.community_progress.staff_emails%')]
        private readonly array $staffEmails,
        #[Autowire('%app.community_progress.staff_logins%')]
        private readonly array $staffLogins,
    ) {
    }

    /**
     * Everything the page shows, in one read: both counters against both
     * targets.
     *
     * @return array{contributors:int, target_contributors:int, data_points:int, target_data_points:int}
     */
    public function summary(): array
    {
        return [
            'contributors' => $this->contributors(),
            'target_contributors' => $this->targetContributors,
            'data_points' => $this->dataPoints(),
            'target_data_points' => $this->targetDataPoints,
        ];
    }

    /**
     * A contributor is one identity, counted once however many paths it
     * took: an email where it is known, a GitHub login where it is not.
     * Both halves are small result sets, so the merge is plain PHP —
     * DISTINCT in each query, array_unique across the two, staff removed.
     * The site arm is accounts behind reviewed work: an approved
     * curatorship, an approved translation, a curator post, a published
     * blog post. The appointment itself counts: the application was
     * reviewed, and taking a region is the contribution; waiting for the
     * first decision would make a commitment a task.
     */
    public function contributors(): int
    {
        $github = $this->db->executeQuery(
            'SELECT DISTINCT LOWER(COALESCE(NULLIF(email, \'\'), github_login)) FROM github_contributor'
        )->fetchFirstColumn();

        $people = array_unique([...$github, ...$this->dbContributorEmails()]);

        return \count(array_diff($people, $this->staffEmailsLower(), array_map('strtolower', $this->staffLogins)));
    }

    /**
     * The site half: distinct lower-cased emails behind reviewed work. A
     * blog post counts only once published — a draft is not yet a
     * contribution to the project.
     *
     * @return list<string>
     */
    private function dbContributorEmails(): array
    {
        $sql = <<<'SQL'
            SELECT DISTINCT LOWER(u.email) FROM curator_application a
            JOIN users u ON u.id = a.user_id
            WHERE a.status = 'approved'
            UNION
            SELECT DISTINCT LOWER(u.email) FROM translation_proposal t
            JOIN users u ON u.id = t.submitter_id
            WHERE t.submitter_id IS NOT NULL AND t.status = 'approved'
            UNION
            SELECT DISTINCT LOWER(u.email) FROM curator_post p
            JOIN users u ON u.id = p.author_id
            WHERE p.author_id IS NOT NULL
            UNION
            SELECT DISTINCT LOWER(u.email) FROM blog_post b
            JOIN users u ON u.id = b.author_id
            WHERE b.author_id IS NOT NULL AND b.status = 'published'
            SQL;

        return $this->db->executeQuery($sql)->fetchFirstColumn();
    }

    /**
     * Rider-backed data points: every place a rider's own action put in the
     * dataset or stood behind. Adding and verifying weigh the same, so the
     * count is distinct (account, thing) pairs across the three acts —
     * approved submissions, drawer confirmations, rode-it checks — and one
     * account on one place counts once no matter how many acts it spent.
     * Item ids and route ids share a number space, hence the kind tag.
     */
    public function dataPoints(): int
    {
        $staff = $this->staffEmailsLower();
        $sql = <<<'SQL'
            SELECT COUNT(*) FROM (
                SELECT s.user_id AS uid, 'i' AS kind, s.item_id AS ref
                FROM submission s
                WHERE s.status = 'approved' AND s.type IN ('new', 'edit', 'hazard')
                  AND s.item_id IS NOT NULL
                UNION
                SELECT c.user_id, 'i', c.item_id FROM item_confirmation c
                UNION
                SELECT r.user_id, 'r', r.route_id FROM route_ride r
            ) backed
            SQL;
        if ([] === $staff) {
            return (int) $this->db->executeQuery($sql)->fetchOne();
        }

        return (int) $this->db->executeQuery(
            $sql.' WHERE backed.uid NOT IN (SELECT id FROM users WHERE LOWER(email) IN (:staff))',
            ['staff' => $staff],
            ['staff' => ArrayParameterType::STRING],
        )->fetchOne();
    }

    /** @return list<string> */
    private function staffEmailsLower(): array
    {
        return array_map('strtolower', $this->staffEmails);
    }
}
