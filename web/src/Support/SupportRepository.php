<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Support;

use App\Support\Entity\BugReport;
use App\Support\Entity\ContactMessage;
use App\Support\Entity\ContentReport;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Every read the two support desks and the public lists need.
 *
 * One class rather than two Doctrine repositories: the queries are small, they
 * share their paging and their filter shape, and keeping them together makes it
 * obvious that the public known-issues list and the curator desk read the same
 * table through different filters, which is the property that matters, because
 * getting it wrong publishes a stranger's bug report.
 *
 * @see docs/specs/contact-and-support.md §8
 *
 * @api
 */
final readonly class SupportRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    // -- Content reports, for the curator desk ---------------------------

    /**
     * @return list<ContentReport>
     */
    public function reports(?ReportStatus $status, ?ReportTarget $target, int $limit, int $offset): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('r')
            ->from(ContentReport::class, 'r');

        if (null !== $status) {
            // "Open" on the desk means every report still waiting, taken up or not.
            if (ReportStatus::Open === $status) {
                $qb->andWhere('r.status IN (:status)')->setParameter('status', ReportStatus::open());
            } else {
                $qb->andWhere('r.status = :status')->setParameter('status', $status);
            }
        }
        if (null !== $target) {
            $qb->andWhere('r.targetType = :target')->setParameter('target', $target);
        }

        // Legal grounds first, then newest. This desk sorts where the bug desk
        // deliberately does not, because Article 16 gives the two kinds of
        // report different clocks: an unlawfulness claim has to be handled
        // "timely", a quality complaint has to be handled well.
        /** @var list<ContentReport> $rows */
        $rows = $qb->addSelect('CASE WHEN r.ground IN (:urgent) THEN 0 ELSE 1 END AS HIDDEN urgentFirst')
            ->addOrderBy('urgentFirst', 'ASC')
            ->addOrderBy('r.createdAt', 'DESC')
            ->setParameter('urgent', ReportGround::urgent())
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function countReports(?ReportStatus $status, ?ReportTarget $target): int
    {
        $qb = $this->em->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(ContentReport::class, 'r');

        if (null !== $status) {
            // "Open" on the desk means every report still waiting, taken up or not.
            if (ReportStatus::Open === $status) {
                $qb->andWhere('r.status IN (:status)')->setParameter('status', ReportStatus::open());
            } else {
                $qb->andWhere('r.status = :status')->setParameter('status', $status);
            }
        }
        if (null !== $target) {
            $qb->andWhere('r.targetType = :target')->setParameter('target', $target);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return array<string, int> status value => count, for the filter chips */
    public function reportCountsByStatus(): array
    {
        /** @var list<array{status: ReportStatus, n: int|string}> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('r.status AS status, COUNT(r.id) AS n')
            ->from(ContentReport::class, 'r')
            ->groupBy('r.status')
            ->getQuery()
            ->getResult();

        $out = [];
        foreach (ReportStatus::all() as $status) {
            $out[$status->value] = 0;
        }
        foreach ($rows as $row) {
            $out[$row['status']->value] = (int) $row['n'];
        }

        return $out;
    }

    /** Badge on the moderator tab. Only Open counts: the rest are answered. */
    public function openReportCount(): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(ContentReport::class, 'r')
            ->where('r.status IN (:open)')
            ->setParameter('open', ReportStatus::open())
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Every other open report about the same thing.
     *
     * Ten reports about one route is a different fact from one report, and a
     * curator deciding the first should be able to see the other nine.
     *
     * @return list<ContentReport>
     */
    public function siblingReports(ContentReport $report): array
    {
        /** @var list<ContentReport> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('r')
            ->from(ContentReport::class, 'r')
            ->where('r.targetType = :target')
            ->andWhere('r.targetId = :id')
            ->andWhere('r.id != :self')
            ->setParameter('target', $report->getTargetType())
            ->setParameter('id', $report->getTargetId())
            ->setParameter('self', $report->getId(), 'uuid')
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    // -- Bugs, for the curator desk -------------------------------------

    /**
     * Turn `#123`, a bare `123`, or the old `CC-B-000123`, into the id it names.
     *
     * A reference is a LOOKUP, not a search: a curator pasting the number from
     * a reporter's email wants that one report, not every report that mentions
     * it.
     *
     * **`CC-B-` is kept forever.** The reference was that shape until
     * 2026-08-28 and it is sitting in mail somebody already received. A reporter
     * quoting it in a reply three years from now must still be findable.
     */
    public static function bugIdFromReference(string $query): ?int
    {
        if (1 === preg_match('/^\s*(?:#|cc-b-)?0*(\d{1,9})\s*$/i', $query, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * @return list<BugReport>
     */
    public function bugs(
        ?BugStatus $status,
        ?BugArea $area,
        ?string $query,
        int $limit,
        int $offset,
        ?BugSort $sort = null,
    ): array {
        $sort ??= BugSort::Newest;

        $qb = $this->em->createQueryBuilder()
            ->select('b')
            ->from(BugReport::class, 'b');

        $this->filterBugs($qb, $status, $area, $query);

        // Severity is an enum column, so "worst first" is a CASE, not a plain
        // ORDER BY: the stored strings sort alphabetically, which would put
        // cosmetic above critical. HIDDEN because DQL will not take a CASE
        // expression directly in ORDER BY, and the alias must not reach the
        // hydrator as a column.
        //
        // Paid for only when it is asked for: the default order never builds it.
        if ($sort->bySeverity()) {
            $qb->addSelect(\sprintf(
                'CASE %s ELSE %d END AS HIDDEN severityRank',
                implode(' ', array_map(
                    static fn (BugSeverity $s): string => \sprintf(
                        "WHEN b.severity = '%s' THEN %d",
                        $s->value,
                        $s->weight(),
                    ),
                    BugSeverity::all(),
                )),
                \count(BugSeverity::all()),
            ))->addOrderBy('severityRank', $sort->severityDirection());
        }

        // Recency is always the last word, even inside a severity band: a
        // curator working through cosmetic bugs still wants this week's first.
        /** @var list<BugReport> $rows */
        $rows = $qb->addOrderBy('b.createdAt', $sort->dateDirection())
            // created_at is DATETIME(0), so two reports filed in the same
            // second tie and the database may return them in either order,
            // differently on each page load. The id is the arrival order, so
            // it is the tie-break the date was reaching for.
            ->addOrderBy('b.id', $sort->dateDirection())
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function countBugs(?BugStatus $status, ?BugArea $area, ?string $query = null): int
    {
        $qb = $this->em->createQueryBuilder()
            ->select('COUNT(b.id)')
            ->from(BugReport::class, 'b');

        $this->filterBugs($qb, $status, $area, $query);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * The one place the desk's filters are built, so the rows and the count
     * cannot disagree about what matched.
     *
     * Text search is a plain case-insensitive LIKE across the four fields a
     * curator may search by. Not full-text: this table is small, the queries
     * are one-word, and a tsvector column would be a migration and an index to
     * maintain for a desk that never has ten thousand rows.
     *
     * **The reporter's address is NOT among them.** Curators cannot see an
     * address anywhere on this site, and searching one is the same disclosure
     * by another route: a hit confirms that a named person filed a report.
     *
     * `%` and `_` are escaped, so pasting a URL with an underscore in it does
     * not quietly become a wildcard.
     */
    private function filterBugs(QueryBuilder $qb, ?BugStatus $status, ?BugArea $area, ?string $query): void
    {
        if (null !== $status) {
            $qb->andWhere('b.status = :status')->setParameter('status', $status);
        }
        if (null !== $area) {
            $qb->andWhere('b.area = :area')->setParameter('area', $area);
        }

        $query = null === $query ? '' : trim($query);
        if ('' === $query) {
            return;
        }

        // An exact reference short-circuits everything else.
        $id = self::bugIdFromReference($query);
        if (null !== $id) {
            $qb->andWhere('b.id = :bugId')->setParameter('bugId', $id);

            return;
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query).'%';
        $qb->andWhere($qb->expr()->orX(
            'LOWER(b.title) LIKE LOWER(:q)',
            'LOWER(b.body) LIKE LOWER(:q)',
            'LOWER(b.steps) LIKE LOWER(:q)',
            'LOWER(b.publicTitle) LIKE LOWER(:q)',
        ))->setParameter('q', $like);
    }

    /** @return array<string, int> status value => count, for the filter chips */
    public function bugCountsByStatus(): array
    {
        /** @var list<array{status: BugStatus, n: int|string}> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('b.status AS status, COUNT(b.id) AS n')
            ->from(BugReport::class, 'b')
            ->groupBy('b.status')
            ->getQuery()
            ->getResult();

        $out = [];
        foreach (BugStatus::all() as $status) {
            $out[$status->value] = 0;
        }
        foreach ($rows as $row) {
            $out[$row['status']->value] = (int) $row['n'];
        }

        return $out;
    }

    /** Badge on the moderator tab: what still costs somebody something. */
    public function openBugCount(): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(b.id)')
            ->from(BugReport::class, 'b')
            ->where('b.status IN (:open)')
            ->setParameter('open', BugStatus::open())
            ->getQuery()
            ->getSingleScalarResult();
    }

    // -- Bugs, for the public and for the reporter -----------------------

    /**
     * The known-issues list.
     *
     * Two conditions, and both are load-bearing. `isPublic` is a curator's
     * decision, so nothing reaches this list unread. Resolved issues stay on
     * for a while on purpose: somebody who hits a bug we fixed last week
     * should find it here and know not to report it again. Declined ones
     * do not, because "we are not fixing this" is a conversation with the
     * reporter, not a public notice.
     *
     * @return list<BugReport>
     */
    public function publicIssues(int $limit, int $offset): array
    {
        /** @var list<BugReport> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('b')
            ->from(BugReport::class, 'b')
            ->where('b.isPublic = true')
            ->andWhere('b.status != :declined')
            ->setParameter('declined', BugStatus::Declined)
            ->orderBy('b.updatedAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function countPublicIssues(): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(b.id)')
            ->from(BugReport::class, 'b')
            ->where('b.isPublic = true')
            ->andWhere('b.status != :declined')
            ->setParameter('declined', BugStatus::Declined)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * A rider's own reports, and only their own.
     *
     * @return list<BugReport>
     */
    public function bugsByUser(int $userId, int $limit, int $offset): array
    {
        /** @var list<BugReport> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('b')
            ->from(BugReport::class, 'b')
            ->where('b.userId = :uid')
            ->setParameter('uid', $userId)
            ->orderBy('b.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function countBugsByUser(int $userId): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(b.id)')
            ->from(BugReport::class, 'b')
            ->where('b.userId = :uid')
            ->setParameter('uid', $userId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    // -- Contact messages ------------------------------------------------

    /**
     * @return list<ContactMessage>
     */
    public function messages(?ContactStatus $status, ?ContactTopic $topic, int $limit, int $offset): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('m')
            ->from(ContactMessage::class, 'm');

        if (null !== $status) {
            $qb->andWhere('m.status = :status')->setParameter('status', $status);
        }
        if (null !== $topic) {
            $qb->andWhere('m.topic = :topic')->setParameter('topic', $topic);
        }

        // Anything with a legal deadline sorts above anything without one, then
        // oldest first within each group. A message on a one-month GDPR clock
        // that arrived three weeks ago is more urgent than one that arrived
        // this morning, and a desk sorted purely by arrival hides exactly that.
        /** @var list<ContactMessage> $rows */
        $rows = $qb->orderBy('m.dueAt', 'ASC')
            ->addOrderBy('m.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function countMessages(?ContactStatus $status, ?ContactTopic $topic): int
    {
        $qb = $this->em->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(ContactMessage::class, 'm');

        if (null !== $status) {
            $qb->andWhere('m.status = :status')->setParameter('status', $status);
        }
        if (null !== $topic) {
            $qb->andWhere('m.topic = :topic')->setParameter('topic', $topic);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return array<string, int> status value => count, for the filter chips */
    public function messageCountsByStatus(): array
    {
        /** @var list<array{status: ContactStatus, n: int|string}> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('m.status AS status, COUNT(m.id) AS n')
            ->from(ContactMessage::class, 'm')
            ->groupBy('m.status')
            ->getQuery()
            ->getResult();

        $out = [];
        foreach (ContactStatus::all() as $status) {
            $out[$status->value] = 0;
        }
        foreach ($rows as $row) {
            $out[$row['status']->value] = (int) $row['n'];
        }

        return $out;
    }

    /** Badge on the moderator tab. */
    public function openMessageCount(): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(ContactMessage::class, 'm')
            ->where('m.status IN (:open)')
            ->setParameter('open', [ContactStatus::New, ContactStatus::Open])
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Open, past its promised date. The number that should never be above zero. */
    public function overdueMessageCount(\DateTimeImmutable $now): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(ContactMessage::class, 'm')
            ->where('m.status IN (:open)')
            ->andWhere('m.dueAt IS NOT NULL')
            ->andWhere('m.dueAt < :now')
            ->setParameter('open', [ContactStatus::New, ContactStatus::Open])
            ->setParameter('now', $now)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
