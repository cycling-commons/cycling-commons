<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Messaging;

use App\Messaging\Entity\UserMessage;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The single owner of message writes and reads: system decision outcomes,
 * free-form curator notes, and rider needs-info replies all go through
 * here, along with the dashboard's list, unread-count, and mark-read reads.
 *
 * @see docs/specs/moderation-and-contribution.md §7
 *
 * @api Called by moderation decision handlers, the curator message action,
 *      the rider reply action, and the messages dashboard.
 */
final class MessageService
{
    /**
     * Messages per page on the dashboard. The list used to stop at a hard 100
     * with nothing saying so; anyone past that simply never saw their older
     * decisions again (2026-08-08).
     */
    public const int PER_PAGE = 20;

    private const int BODY_TEXT_MAX_LENGTH = 2000;
    private const string ERROR_TOO_LONG = 'moderate.error.note_too_long';
    private const string ERROR_REQUIRED = 'moderate.error.note_required';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
        /**
         * Where a message goes to be emailed (M7). Queued rather than sent: the
         * system path below runs INSIDE the moderation transaction, and mail
         * announcing a decision that can still roll back is worse than no mail
         * at all. {@see MessageOutbox}.
         */
        private readonly MessageOutbox $outbox,
    ) {
    }

    /**
     * Records a system decision outcome. Persists WITHOUT flushing: callers
     * call this from inside a decision transaction (for example,
     * ModerationService::decide's `wrapInTransaction` closure), whose own
     * flush-on-commit writes this row alongside the decision. An extra
     * flush here would be redundant, and could flush a still-inconsistent
     * unit of work.
     *
     * `$curatorNote`, when non-empty after trimming, is stored in
     * `body_text` next to the translated `body_key` headline, so the rider
     * sees the curator's note under it.
     *
     * `user_message.user_id` carries a real FK (`ON DELETE CASCADE`) to
     * `users`, but every referenced entity's author/proposer column
     * (`submission.user_id`, `route_suggestion.user_id`,
     * `recommended_route.proposed_by`) is a plain no-FK int that survives
     * account deletion. If the recipient's account is already gone, this
     * method returns null instead of letting the INSERT violate the FK: no
     * recipient, no message. The durable audit still lives on the row or
     * its change history, so the decision itself is never lost.
     *
     * @see docs/specs/moderation-and-contribution.md §7.6
     *
     * @param array<string, mixed> $bodyParams
     *
     * @throws \InvalidArgumentException if the trimmed note exceeds 2000 characters (moderate.error.note_too_long)
     */
    public function sendSystem(
        int $userId,
        UserMessageKind $kind,
        string $channel,
        int $refId,
        string $refLabel,
        string $bodyKey,
        array $bodyParams = [],
        ?string $curatorNote = null,
    ): ?UserMessage {
        if (!$this->recipientExists($userId)) {
            return null;
        }

        $note = $this->normalizeBody($curatorNote, true);

        $message = new UserMessage(
            $userId,
            $kind,
            'system',
            null,
            $channel,
            $refId,
            $refLabel,
            $bodyKey,
            [] !== $bodyParams ? $bodyParams : null,
            $note,
        );
        $this->em->persist($message);
        $this->outbox->queue($message);

        return $message;
    }

    /**
     * Records a free-form curator note to a rider. Standalone action, not
     * inside a decision transaction: persists and flushes immediately.
     *
     * `$userId` is resolved by the caller from the same no-FK author/
     * proposer columns `sendSystem()` guards against (submission.user_id,
     * route_suggestion.user_id, recommended_route.proposed_by). A deleted
     * account is the same already-dangling-id case there, so this method
     * needs the identical existence guard, not just for consistency.
     *
     * @throws \InvalidArgumentException if the trimmed body is empty or exceeds 2000 characters
     */
    public function sendCurator(int $userId, int $curatorId, string $channel, int $refId, string $refLabel, string $bodyText, ?Uuid $mediaId = null): ?UserMessage
    {
        $body = $this->normalizeBody($bodyText, false);

        if (!$this->recipientExists($userId)) {
            return null;
        }

        $message = new UserMessage($userId, UserMessageKind::CuratorMessage, 'curator', $curatorId, $channel, $refId, $refLabel, null, null, $body, $mediaId);
        $this->em->persist($message);
        $this->em->flush();
        $this->outbox->queue($message);

        return $message;
    }

    /**
     * Records a rider's needs-info reply, delivered TO the curator (the
     * message recipient is the curator, not the rider). Standalone action:
     * persists and flushes immediately.
     *
     * `submission.decided_by` (the source of `$recipientCuratorId`) is
     * another no-FK column, so the deciding curator's account can be gone
     * by the time the rider replies. `MessagesController::reply` already
     * checks this before opening the transaction, and flashes the same
     * `messages.reply_too_late` outcome as an already-resolved submission.
     * This guard is a backstop for that same race, for any future direct
     * caller. The window is small, so it is only documented here rather
     * than specially handled by the caller's transaction.
     *
     * @throws \InvalidArgumentException if the trimmed body is empty or exceeds 2000 characters
     */
    public function sendRiderReply(int $recipientCuratorId, int $riderId, string $channel, int $refId, string $refLabel, string $bodyText, ?Uuid $mediaId = null): ?UserMessage
    {
        $body = $this->normalizeBody($bodyText, false);

        if (!$this->recipientExists($recipientCuratorId)) {
            return null;
        }

        $message = new UserMessage($recipientCuratorId, UserMessageKind::RiderReply, 'rider', $riderId, $channel, $refId, $refLabel, null, null, $body, $mediaId);
        $this->em->persist($message);
        $this->em->flush();
        $this->outbox->queue($message);

        return $message;
    }

    /**
     * The reader's own thread: what was sent TO them, and what they sent
     * themselves.
     *
     * A rider's needs-info reply is addressed to the deciding curator
     * (sendRiderReply), so a recipient-only list showed the rider the
     * curator's question and then nothing — their own answer vanished, and
     * the page read as though they had never replied. `sender_id` carries the
     * author of every non-system message, which is what makes the sent half
     * recoverable without a second table.
     *
     * Unread counting and mark-read stay recipient-only on purpose: a message
     * you wrote is not news to you.
     *
     * Returns THREAD HEADS only - everything except the reader's own replies,
     * which {@see \App\Controller\MessagesController} attaches under the
     * question they answer. Paging over heads is what keeps a question and its
     * answer on the same page: paging the flat list would eventually put a
     * reply at the foot of one page and the question it belongs to at the top
     * of the next, where each reads as an orphan. (A reply whose question row
     * is gone - retention sweep, deleted account - has nothing to attach to and
     * does not render; the reply itself is not the record, the decision is.)
     *
     * @return list<UserMessage>
     */
    public function listFor(
        int $userId,
        int $offset = 0,
        int $limit = self::PER_PAGE,
        ?MessageCategory $category = null,
        bool $unreadOnly = false,
    ): array {
        $qb = $this->em->getRepository(UserMessage::class)->createQueryBuilder('m');

        if (null !== $category) {
            $qb->andWhere('m.kind IN (:kinds)')->setParameter('kinds', $category->kinds());
        }
        if ($unreadOnly) {
            // Recipient-only, matching unreadCount(): a message the reader
            // WROTE was never unread to them, so "unread" must not surface
            // their own sent half (which the senderId arm below includes).
            $qb->andWhere('m.readAt IS NULL')->andWhere('m.userId = :id');
        }

        /** @var list<UserMessage> $messages */
        $messages = $qb
            ->andWhere($qb->expr()->orX('m.userId = :id', 'm.senderId = :id'))
            // A rider's answer to a needs-info question is MODERATION work, not
            // personal correspondence, so it does not belong in the inbox a
            // rider uses for their own contributions (owner, 2026-08-03). It is
            // addressed to the deciding curator only so the desk can find it:
            // SubmissionQueue's LATERAL join reads this row to render "Rider
            // replied" on the queue card and in the map drawer, and the reply
            // puts the submission back in the pending queue. Excluded HERE
            // rather than not written, because deleting the row would take the
            // desk's copy of the answer with it.
            //
            // Combined with the `senderId = :id` half of the clause above, the
            // only rider replies that ever reached this list were the reader's
            // OWN - and those are precisely what the controller re-attaches to
            // the question they answer. So heads exclude the kind outright and
            // repliesBySender() brings the reader's back, for the questions on
            // this page only.
            ->andWhere('m.kind != :moderationReply')
            ->setParameter('moderationReply', UserMessageKind::RiderReply)
            ->setParameter('id', $userId)
            ->orderBy('m.createdAt', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $messages;
    }

    /**
     * How many thread heads {@see self::listFor()} would return in total.
     * Same filters, or paging walks into pages the list refuses to render.
     */
    public function countFor(int $userId, ?MessageCategory $category = null, bool $unreadOnly = false): int
    {
        $sql = 'SELECT COUNT(*) FROM user_message
                 WHERE (user_id = :u OR sender_id = :u) AND kind <> :moderationReply';
        $params = ['u' => $userId, 'moderationReply' => UserMessageKind::RiderReply->value];
        $types = [];

        if (null !== $category) {
            $sql .= ' AND kind IN (:kinds)';
            $params['kinds'] = $category->kindValues();
            $types['kinds'] = ArrayParameterType::STRING;
        }
        if ($unreadOnly) {
            $sql .= ' AND read_at IS NULL AND user_id = :u';
        }

        return (int) $this->db->fetchOne($sql, $params, $types);
    }

    /**
     * How many messages sit on each shelf, and how many are unread, in one
     * round trip — so the filter can say "Notices 3" rather than making the
     * reader click each one to find out whether it holds anything.
     *
     * Unread is recipient-only, like {@see unreadCount()}. The per-category
     * totals are not: they count the same set the unfiltered list shows, sent
     * half included, or the numbers on the chips would not add up to the
     * number on "All".
     *
     * @return array{total:int, unread:int, byCategory:array<string,int>}
     */
    public function countsFor(int $userId): array
    {
        /** @var list<array{kind:string, n:int|string, unread:int|string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT kind,
                    COUNT(*) AS n,
                    COUNT(*) FILTER (WHERE read_at IS NULL AND user_id = :u) AS unread
               FROM user_message
              WHERE (user_id = :u OR sender_id = :u) AND kind <> :moderationReply
              GROUP BY kind',
            ['u' => $userId, 'moderationReply' => UserMessageKind::RiderReply->value],
        );

        $shelfOf = [];
        foreach (MessageCategory::cases() as $category) {
            foreach ($category->kindValues() as $kind) {
                $shelfOf[$kind] = $category->value;
            }
        }

        $byCategory = array_fill_keys(array_map(
            static fn (MessageCategory $c): string => $c->value,
            MessageCategory::cases(),
        ), 0);
        $total = 0;
        $unread = 0;
        foreach ($rows as $row) {
            $total += (int) $row['n'];
            $unread += (int) $row['unread'];
            // A kind nobody filed lands on no shelf rather than on the wrong
            // one; MessageCategoryTest fails before that can ship.
            if (isset($shelfOf[$row['kind']])) {
                $byCategory[$shelfOf[$row['kind']]] += (int) $row['n'];
            }
        }

        return ['total' => $total, 'unread' => $unread, 'byCategory' => $byCategory];
    }

    /**
     * The reader's own needs-info replies for the given submissions, oldest
     * first, so the controller can pair each with the question it answers.
     *
     * Scoped to `sender_id = :u`: a reply addressed to a curator is the
     * curator's incoming message, not something this reader may pull up by
     * quoting a submission id.
     *
     * @param list<int> $refIds
     *
     * @return list<UserMessage>
     */
    public function repliesBySender(int $userId, array $refIds): array
    {
        if ([] === $refIds) {
            return [];
        }

        $qb = $this->em->getRepository(UserMessage::class)->createQueryBuilder('m');

        /** @var list<UserMessage> $replies */
        $replies = $qb
            ->where('m.senderId = :id')
            ->andWhere('m.kind = :moderationReply')
            ->andWhere('m.channel = :channel')
            ->andWhere($qb->expr()->in('m.refId', ':refIds'))
            ->setParameter('id', $userId)
            ->setParameter('moderationReply', UserMessageKind::RiderReply)
            ->setParameter('channel', 'submission')
            ->setParameter('refIds', $refIds)
            ->orderBy('m.createdAt', 'ASC')
            ->addOrderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $replies;
    }

    public function unreadCount(int $userId): int
    {
        // Matches listFor()'s exclusion, or the chip would count a message the
        // page then refuses to show — a badge pointing at nothing.
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM user_message
             WHERE user_id = :u AND read_at IS NULL AND kind <> :moderationReply',
            ['u' => $userId, 'moderationReply' => UserMessageKind::RiderReply->value],
        );
    }

    public function markAllRead(int $userId): void
    {
        // Everything the page can show. Moderation replies are excluded from
        // the list, so marking them read here would quietly consume a state
        // the reader was never shown.
        $this->db->executeStatement(
            'UPDATE user_message SET read_at = now()
             WHERE user_id = :u AND read_at IS NULL AND kind <> :moderationReply',
            ['u' => $userId, 'moderationReply' => UserMessageKind::RiderReply->value],
        );
    }

    /**
     * Marks read only the messages actually rendered.
     *
     * Since the dashboard pages (2026-08-08), marking everything read on a
     * visit would consume the unread state of messages sitting on page 3 that
     * the reader has not seen - the unread marker would then be a lie, and the
     * chip would drop to zero over messages nobody opened. Only rows addressed
     * TO the reader are touched: a message you sent was never unread to you.
     *
     * @param list<int> $ids
     */
    public function markRead(int $userId, array $ids): void
    {
        if ([] === $ids) {
            return;
        }

        $this->db->executeStatement(
            'UPDATE user_message SET read_at = now()
             WHERE user_id = :u AND read_at IS NULL AND id IN (:ids)',
            ['u' => $userId, 'ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        );
    }

    /**
     * True when `$userId` still has a row in `users`. This is the
     * deleted-recipient guard shared by every send* method (see their
     * docblocks).
     */
    private function recipientExists(int $userId): bool
    {
        return false !== $this->db->fetchOne('SELECT 1 FROM users WHERE id = :id', ['id' => $userId]);
    }

    /**
     * Trims, nulls-out-if-empty (only when `$allowEmpty`), and enforces the
     * 2000-character cap shared by curator notes/messages/replies.
     *
     * @throws \InvalidArgumentException if empty when required, or over the cap
     */
    private function normalizeBody(?string $text, bool $allowEmpty): ?string
    {
        $trimmed = null !== $text ? trim($text) : '';

        if ('' === $trimmed) {
            if ($allowEmpty) {
                return null;
            }
            throw new \InvalidArgumentException(self::ERROR_REQUIRED);
        }

        if (mb_strlen($trimmed) > self::BODY_TEXT_MAX_LENGTH) {
            throw new \InvalidArgumentException(self::ERROR_TOO_LONG);
        }

        return $trimmed;
    }
}
