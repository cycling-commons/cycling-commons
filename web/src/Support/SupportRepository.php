<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Support;

use App\Support\Entity\BugReport;
use App\Support\Entity\ContactMessage;
use Doctrine\ORM\EntityManagerInterface;

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

    // -- Bugs, for the curator desk -------------------------------------

    /**
     * @return list<BugReport>
     */
    public function bugs(?BugStatus $status, ?BugArea $area, int $limit, int $offset): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('b')
            ->from(BugReport::class, 'b');

        if (null !== $status) {
            $qb->andWhere('b.status = :status')->setParameter('status', $status);
        }
        if (null !== $area) {
            $qb->andWhere('b.area = :area')->setParameter('area', $area);
        }

        // Newest first, and nothing clever: a desk that reorders itself by
        // severity hides the report that arrived thirty seconds ago, which is
        // the one most likely to be about something that just broke.
        /** @var list<BugReport> $rows */
        $rows = $qb->orderBy('b.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function countBugs(?BugStatus $status, ?BugArea $area): int
    {
        $qb = $this->em->createQueryBuilder()
            ->select('COUNT(b.id)')
            ->from(BugReport::class, 'b');

        if (null !== $status) {
            $qb->andWhere('b.status = :status')->setParameter('status', $status);
        }
        if (null !== $area) {
            $qb->andWhere('b.area = :area')->setParameter('area', $area);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
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
