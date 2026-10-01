<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Moderation;

use App\Catalog\Entity\Item;
use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\Entity\RouteSuggestion;
use App\Catalog\Entity\Submission;
use App\Catalog\ItemState;
use App\Catalog\SubmissionType;
use App\Contribution\SubmissionChangeSummary;
use App\Media\MediaDisposalService;
use App\Messaging\MessageService;
use App\Service\AdminActionLogger;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The curators' Trash: what it holds, and the purge that empties it.
 *
 * A trashed submission, route correction or route proposal is kept with its
 * message thread for {@see self::TRASH_DAYS} days, hidden everywhere but the
 * Trash list, and a curator of its area can restore it (ModerationService::
 * restoreSubmission(), RouteModerationService::restoreSuggestion() and
 * ::restoreProposal()). After that the purge deletes it for good: its photos
 * and, for a new place, its unapproved pin, as Trash used to at once, then
 * its thread and the row. Legal hold outlives the bin: a held row is never
 * purged.
 *
 * @see docs/specs/moderation-and-contribution.md §6
 *
 * @api
 */
final class TrashBin
{
    /** Days a trashed row waits in the bin before the purge deletes it. */
    public const int TRASH_DAYS = 30;

    public const string KIND_SUBMISSION = 'submission';
    public const string KIND_CORRECTION = 'correction';
    public const string KIND_PROPOSAL = 'proposal';

    public const int PER_PAGE = 25;

    private const string CACHE_KEY = 'moderation_trash_purge_last';
    private const int CACHE_TTL_SECONDS = 3600;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
        private readonly ClockInterface $clock,
        private readonly CacheInterface $cache,
        private readonly MediaDisposalService $mediaDisposal,
        private readonly MessageService $messages,
        private readonly AdminActionLogger $adminLog,
        private readonly SubmissionChangeSummary $changes,
    ) {
    }

    /** The day a row trashed at `$trashedAt` is deleted for good. */
    public static function purgeOn(\DateTimeImmutable $trashedAt): \DateTimeImmutable
    {
        return $trashedAt->modify(\sprintf('+%d days', self::TRASH_DAYS));
    }

    /**
     * Deletes every row that has been in the bin longer than TRASH_DAYS, each
     * in its own transaction, with its photos and its thread. Idempotent.
     *
     * @return array{submissions: int, corrections: int, proposals: int} rows deleted
     */
    public function purgeExpired(?\DateTimeImmutable $now = null): array
    {
        $cutoff = ($now ?? $this->clock->now())->modify(\sprintf('-%d days', self::TRASH_DAYS))->format('Y-m-d H:i:s');

        $counts = ['submissions' => 0, 'corrections' => 0, 'proposals' => 0];

        // Legal hold outlives the bin (docs/specs/photo-uploads.md §6d).
        foreach ($this->db->fetchFirstColumn(
            "SELECT id FROM submission WHERE status = 'trashed' AND trashed_at < :cutoff AND escalated_at IS NULL ORDER BY id",
            ['cutoff' => $cutoff],
        ) as $id) {
            $counts['submissions'] += $this->purgeSubmission((int) $id);
        }
        foreach ($this->db->fetchFirstColumn(
            "SELECT id FROM route_suggestion WHERE status = 'trashed' AND trashed_at < :cutoff ORDER BY id",
            ['cutoff' => $cutoff],
        ) as $id) {
            $counts['corrections'] += $this->purgeCorrection((int) $id);
        }
        foreach ($this->db->fetchFirstColumn(
            "SELECT id FROM recommended_route WHERE state = 'trashed' AND trashed_at < :cutoff ORDER BY id",
            ['cutoff' => $cutoff],
        ) as $id) {
            $counts['proposals'] += $this->purgeProposal((int) $id);
        }

        return $counts;
    }

    /** Fire-and-forget purge, throttled; never throws. */
    public function sweepOpportunistically(): void
    {
        try {
            $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): true {
                $item->expiresAfter(self::CACHE_TTL_SECONDS);
                $this->purgeExpired();

                return true;
            });
        } catch (\Throwable) {
            // Housekeeping only: a failed purge must not break the desk.
        }
    }

    private function purgeSubmission(int $id): int
    {
        return $this->em->wrapInTransaction(function () use ($id): int {
            $submission = $this->em->find(Submission::class, $id);
            if (null === $submission || !$submission->isTrashed() || $submission->isEscalated()) {
                return 0;
            }
            $this->mediaDisposal->purgeForSubmission($id);
            // A new place that never got past the queue goes with it.
            if (SubmissionType::NewItem === $submission->getType() && null !== $submission->getItemId()) {
                $item = $this->em->find(Item::class, $submission->getItemId());
                if (null !== $item && ItemState::Trashed === $item->getState()) {
                    $this->em->remove($item);
                }
            }
            $this->messages->deleteThread('submission', $id);
            $this->em->remove($submission);
            $this->adminLog->log(null, TrashActions::PurgeSubmission, null, \sprintf('SUB-%d', $id));

            return 1;
        });
    }

    private function purgeCorrection(int $id): int
    {
        return $this->em->wrapInTransaction(function () use ($id): int {
            $suggestion = $this->em->find(RouteSuggestion::class, $id);
            if (null === $suggestion || !$suggestion->isTrashed()) {
                return 0;
            }
            $this->mediaDisposal->purgeForRoute($suggestion->getRouteId(), $id);
            $this->messages->deleteThread('correction', $id);
            $this->em->remove($suggestion);
            $this->adminLog->log(null, TrashActions::PurgeCorrection, null, \sprintf('suggestion %d on route %d', $id, $suggestion->getRouteId()));

            return 1;
        });
    }

    private function purgeProposal(int $id): int
    {
        return $this->em->wrapInTransaction(function () use ($id): int {
            $route = $this->em->find(RecommendedRoute::class, $id);
            if (null === $route || !$route->isTrashed()) {
                return 0;
            }
            $this->mediaDisposal->purgeForRoute($id, null);
            $this->messages->deleteThread('route', $id);
            $this->em->remove($route);
            $this->adminLog->log(null, TrashActions::PurgeRouteProposal, null, \sprintf('route %d', $id));

            return 1;
        });
    }

    /** How many rows in the bin this scope may see, for the pager and the chip. */
    public function count(ModerationScope $scope): int
    {
        [$union, $params, $types] = $this->union($scope);

        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM ('.$union.') t', $params, $types);
    }

    /**
     * One page of the bin, newest first, with what each row says and its thread.
     *
     * @return list<array{kind: string, id: int, title: string, type: string, letter: string, who: ?string, whoUuid: ?string, from: string, trashedBy: ?string, trashedByUuid: ?string, trashedAgo: string, purgeOn: \DateTimeImmutable, changes: list<array{label: string, was: ?string, now: string}>, note: ?string, photos: int, thread: list<array{who: string, body: string, when: string}>}>
     */
    public function page(ModerationScope $scope, int $page = 1, int $perPage = self::PER_PAGE): array
    {
        [$union, $params, $types] = $this->union($scope);
        $perPage = max(1, $perPage);

        /** @var list<array{kind: string, id: int|string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT kind, id FROM ('.$union.') t ORDER BY trashed_at DESC, kind, id DESC LIMIT :lim OFFSET :off',
            [...$params, 'lim' => $perPage, 'off' => (max(1, $page) - 1) * $perPage],
            [...$types, 'lim' => ParameterType::INTEGER, 'off' => ParameterType::INTEGER],
        );

        $now = $this->clock->now();
        $out = [];
        foreach ($rows as $row) {
            $entry = match ($row['kind']) {
                self::KIND_SUBMISSION => $this->submissionEntry((int) $row['id'], $now),
                self::KIND_CORRECTION => $this->correctionEntry((int) $row['id'], $now),
                default => $this->proposalEntry((int) $row['id'], $now),
            };
            if (null !== $entry) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * The bin in this scope as one union of the three kinds.
     *
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function union(ModerationScope $scope): array
    {
        $sub = $scope->sqlFragment('s');
        $route = $scope->sqlFragment('r');
        $and = static fn (array $frag): string => '' !== $frag['sql'] ? ' AND '.$frag['sql'] : '';

        $sql = "SELECT 'submission' AS kind, s.id, s.trashed_at FROM submission s
                 WHERE s.status = 'trashed' AND s.escalated_at IS NULL".$and($sub)."
                UNION ALL
                SELECT 'correction' AS kind, rs.id, rs.trashed_at FROM route_suggestion rs
                  LEFT JOIN recommended_route r ON r.id = rs.route_id
                 WHERE rs.status = 'trashed'".$and($route)."
                UNION ALL
                SELECT 'proposal' AS kind, r.id, r.trashed_at FROM recommended_route r
                 WHERE r.state = 'trashed'".$and($route);

        return [$sql, $sub['params'] + $route['params'], $sub['types'] + $route['types']];
    }

    /** @return array<string, mixed>|null */
    private function submissionEntry(int $id, \DateTimeImmutable $now): ?array
    {
        $s = $this->em->find(Submission::class, $id);
        if (null === $s || !$s->isTrashed() || null === $s->getTrashedAt()) {
            return null;
        }
        $who = $this->rider($s->getUserId());
        $by = $this->colleague($s->getTrashedBy());

        return [
            'kind' => self::KIND_SUBMISSION,
            'id' => $id,
            'title' => $s->getTitle(),
            'type' => $s->getType()->value,
            'letter' => $s->getLetter(),
            'who' => $who['name'],
            'whoUuid' => $who['uuid'],
            'from' => $s->getTrashedFrom()->value ?? '',
            'trashedBy' => $by['name'] ?? null,
            'trashedByUuid' => $by['uuid'] ?? null,
            'trashedAgo' => RelativeTime::ago($s->getTrashedAt(), $now),
            'purgeOn' => self::purgeOn($s->getTrashedAt()),
            'changes' => $this->changes->rows($s),
            'note' => $s->getDecisionNote(),
            'photos' => $this->photoCount('submission_id = :id', ['id' => $id]),
            'thread' => $this->thread('submission', $id, $now),
        ];
    }

    /** @return array<string, mixed>|null */
    private function correctionEntry(int $id, \DateTimeImmutable $now): ?array
    {
        $s = $this->em->find(RouteSuggestion::class, $id);
        if (null === $s || !$s->isTrashed() || null === $s->getTrashedAt()) {
            return null;
        }
        $route = $this->em->find(RecommendedRoute::class, $s->getRouteId());
        $who = $this->rider($s->getUserId());
        $by = $this->colleague($s->getTrashedBy());
        $changes = [];
        foreach ($s->getChanges() ?? [] as $field => $pair) {
            $changes[] = [
                'label' => (string) $field,
                'was' => self::text($pair['was'] ?? null),
                'now' => self::text($pair['now'] ?? null) ?? '',
            ];
        }

        return [
            'kind' => self::KIND_CORRECTION,
            'id' => $id,
            'title' => $route?->getName() ?? \sprintf('route-%d', $s->getRouteId()),
            'type' => 'correction',
            'letter' => 'R',
            'who' => $who['name'],
            'whoUuid' => $who['uuid'],
            'from' => $s->getTrashedFrom()->value ?? '',
            'trashedBy' => $by['name'] ?? null,
            'trashedByUuid' => $by['uuid'] ?? null,
            'trashedAgo' => RelativeTime::ago($s->getTrashedAt(), $now),
            'purgeOn' => self::purgeOn($s->getTrashedAt()),
            'changes' => $changes,
            'note' => $s->getNote(),
            'reason' => $s->getReason()->value,
            'photos' => $this->photoCount('route_suggestion_id = :id', ['id' => $id]),
            'thread' => $this->thread('correction', $id, $now),
        ];
    }

    /** @return array<string, mixed>|null */
    private function proposalEntry(int $id, \DateTimeImmutable $now): ?array
    {
        $r = $this->em->find(RecommendedRoute::class, $id);
        if (null === $r || !$r->isTrashed() || null === $r->getTrashedAt()) {
            return null;
        }
        $who = null !== $r->getProposedBy() ? $this->rider($r->getProposedBy()) : ['name' => null, 'uuid' => null];
        $by = $this->colleague($r->getTrashedBy());
        $km = null !== $r->getDistanceM() ? round($r->getDistanceM() / 1000, 1) : null;

        return [
            'kind' => self::KIND_PROPOSAL,
            'id' => $id,
            'title' => $r->getName(),
            'type' => 'route',
            'letter' => 'R',
            'who' => $who['name'],
            'whoUuid' => $who['uuid'],
            'from' => $r->getTrashedFrom()->value ?? '',
            'trashedBy' => $by['name'] ?? null,
            'trashedByUuid' => $by['uuid'] ?? null,
            'trashedAgo' => RelativeTime::ago($r->getTrashedAt(), $now),
            'purgeOn' => self::purgeOn($r->getTrashedAt()),
            'changes' => [],
            'note' => null,
            'km' => $km,
            'photos' => $this->photoCount('route_id = :id AND route_suggestion_id IS NULL', ['id' => $id]),
            'thread' => $this->thread('route', $id, $now),
        ];
    }

    /** @return array{name: ?string, uuid: ?string} */
    private function rider(int $userId): array
    {
        $row = $this->db->fetchAssociative(
            'SELECT pseudonym, display_name, public_profile, uuid FROM users WHERE id = :id',
            ['id' => $userId],
        );
        if (false === $row) {
            // A removed account: DeskRider names nobody.
            return DeskRider::of(null, null, null, null);
        }

        return DeskRider::of($row['pseudonym'], $row['display_name'], $row['public_profile'], $row['uuid']);
    }

    /** @return array{name: string, uuid: ?string}|null */
    private function colleague(?int $userId): ?array
    {
        if (null === $userId) {
            return null;
        }
        $row = $this->db->fetchAssociative(
            'SELECT display_name, public_profile, uuid FROM users WHERE id = :id',
            ['id' => $userId],
        );

        return false === $row ? null : DeskRider::colleague($row['display_name'], $row['public_profile'], $row['uuid']);
    }

    /** @param array<string, int> $params */
    private function photoCount(string $where, array $params): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM media_upload WHERE '.$where, $params);
    }

    /**
     * The conversation kept with the row: every message on its thread that
     * carries words, oldest first. Outcome messages without a note are left
     * out: they say only what the status already says.
     *
     * @return list<array{who: string, body: string, when: string}>
     */
    private function thread(string $channel, int $refId, \DateTimeImmutable $now): array
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT sender, body_text, created_at FROM user_message
              WHERE channel = :channel AND ref_id = :ref AND body_text IS NOT NULL AND body_text <> ''
              ORDER BY created_at, id",
            ['channel' => $channel, 'ref' => $refId],
        );

        return array_map(static fn (array $r): array => [
            'who' => 'rider' === $r['sender'] ? 'rider' : 'curator',
            'body' => (string) $r['body_text'],
            'when' => RelativeTime::ago(new \DateTimeImmutable((string) $r['created_at']), $now),
        ], $rows);
    }

    private static function text(mixed $value): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }
        if (\is_scalar($value)) {
            return (string) $value;
        }

        $json = json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        return false === $json ? null : $json;
    }
}
