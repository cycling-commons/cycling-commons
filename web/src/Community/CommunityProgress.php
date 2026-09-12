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
     * @param list<string> $excludedEmails steward accounts, never counted
     * @param list<string> $excludedLogins steward GitHub logins, never counted
     * @param list<string> $excludedRoles  steward roles (e.g., ROLE_ADMIN), never counted
     */
    public function __construct(
        private readonly Connection $db,
        #[Autowire('%app.community_progress.target_contributors%')]
        private readonly int $targetContributors,
        #[Autowire('%app.community_progress.target_data_points%')]
        private readonly int $targetDataPoints,
        #[Autowire('%app.community_progress.excluded_emails%')]
        private readonly array $excludedEmails,
        #[Autowire('%app.community_progress.excluded_logins%')]
        private readonly array $excludedLogins,
        #[Autowire('%app.community_progress.excluded_roles%')]
        private readonly array $excludedRoles,
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

        // One diff, not two. The GitHub half is keyed by email where GitHub
        // publishes one and by login where it does not, so both exclusion
        // lists have to be subtracted from the same merged set; subtracting
        // the emails from the site half alone would let a steward through on
        // the login key.
        return \count(array_diff(
            $people,
            $this->excludedEmailsLower(),
            array_map('strtolower', $this->excludedLogins),
        ));
    }

    /**
     * The site half: distinct lower-cased emails behind reviewed work.
     *
     * Counted by the reviewed right, not by the tables it writes to: the
     * role arm catches every account granted a community right — curator
     * today, media manager tomorrow — without a new arm per permission type.
     * The application arm keeps the historical record: a curator who later
     * retires and loses the role did still arrive. The translation arm is the
     * only content-shaped one, and it earns its place: any logged-in user may
     * propose, so it admits people who never held a right. Posts and blog
     * entries need no arm — their authors are right-holders by the permission
     * gate, already counted.
     *
     * @return list<string>
     */
    private function dbContributorEmails(): array
    {
        $sql = <<<'SQL'
            SELECT DISTINCT LOWER(u.email) FROM users u
            WHERE EXISTS (
                SELECT 1 FROM jsonb_array_elements_text(u.roles::jsonb) AS granted(role)
                WHERE granted.role <> 'ROLE_USER'
            )
            UNION
            SELECT DISTINCT LOWER(u.email) FROM curator_application a
            JOIN users u ON u.id = a.user_id
            WHERE a.status = 'approved'
            UNION
            SELECT DISTINCT LOWER(u.email) FROM translation_proposal t
            JOIN users u ON u.id = t.submitter_id
            WHERE t.submitter_id IS NOT NULL AND t.status = 'approved'
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
        $excluded = $this->excludedEmailsLower();
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
        if ([] === $excluded) {
            return (int) $this->db->executeQuery($sql)->fetchOne();
        }

        return (int) $this->db->executeQuery(
            $sql.' WHERE backed.uid NOT IN (SELECT id FROM users WHERE LOWER(email) IN (:excluded))',
            ['excluded' => $excluded],
            ['excluded' => ArrayParameterType::STRING],
        )->fetchOne();
    }

    /**
     * Every account neither counter may count, as lower-cased emails.
     *
     * Two ways in, because a steward can be named or granted. The configured
     * addresses are the named ones. The roles are the granted ones, and they
     * matter because {@see self::dbContributorEmails()} counts any account
     * holding a role other than ROLE_USER: without this, appointing a
     * moderator would raise the count of external contributors by one, which
     * is the opposite of what the trigger measures.
     *
     * Exact granted roles, not the hierarchy: the column stores what was
     * given, and a config naming ROLE_ADMIN should not quietly also mean
     * every role ROLE_ADMIN happens to reach today.
     *
     * @return list<string>
     */
    private function excludedEmailsLower(): array
    {
        $named = array_map('strtolower', $this->excludedEmails);
        if ([] === $this->excludedRoles) {
            return array_values(array_unique($named));
        }

        /** @var list<string> $granted */
        $granted = $this->db->executeQuery(
            <<<'SQL'
                SELECT DISTINCT LOWER(u.email) FROM users u
                WHERE EXISTS (
                    SELECT 1 FROM jsonb_array_elements_text(u.roles::jsonb) AS held(role)
                    WHERE held.role IN (:roles)
                )
                SQL,
            ['roles' => $this->excludedRoles],
            ['roles' => ArrayParameterType::STRING],
        )->fetchFirstColumn();

        return array_values(array_unique([...$named, ...$granted]));
    }
}
