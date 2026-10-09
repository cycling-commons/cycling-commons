<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Messaging;

use App\Messaging\Entity\UserMessage;
use App\Moderation\StatementOfReasons;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * System outcomes, curator notes, and rider replies — plus dashboard reads.
 *
 * @see docs/specs/moderation-and-contribution.md §7
 *
 * @api
 */
final class MessageService
{
    public const int PER_PAGE = 20;

    /** The channel of a statement of reasons sent on its own (DSA Article 17). */
    public const string STATEMENT_CHANNEL = 'statement';

    private const int BODY_TEXT_MAX_LENGTH = 2000;
    private const string ERROR_TOO_LONG = 'moderate.error.note_too_long';
    private const string ERROR_REQUIRED = 'moderate.error.note_required';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
        // Queued, not sent: sendSystem() runs inside the moderation transaction.
        private readonly MessageOutbox $outbox,
    ) {
    }

    /**
     * Persist without flushing — callers are already inside a decision transaction.
     * Returns null if the recipient account is gone (FK vs no-FK authors).
     *
     * @see docs/specs/moderation-and-contribution.md §7.6
     *
     * A decision that restricts what the rider added carries its statement of
     * reasons (DSA Article 17) on the same row, under
     * {@see StatementOfReasons::PARAM}: the inbox shows it under the message,
     * and the email is the statement ({@see MessageMailer}).
     *
     * @param array<string, mixed> $bodyParams
     *
     * @throws \InvalidArgumentException if the trimmed note exceeds 2000 characters
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
        ?StatementOfReasons $statement = null,
    ): ?UserMessage {
        if (!$this->recipientExists($userId)) {
            return null;
        }

        $note = $this->normalizeBody($curatorNote, true);
        if (null !== $statement) {
            $bodyParams[StatementOfReasons::PARAM] = $statement->toArray();
        }

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
     * A statement of reasons on its own, for a decision that has no message
     * of its own to ride: a contribution moved to Trash as abuse, a place
     * taken off the map, an upheld report. Its own channel, so the thread it
     * is about can go to Trash or be purged without taking it along.
     * Persists without flushing, like {@see sendSystem()}.
     */
    public function sendStatement(int $userId, int $refId, StatementOfReasons $statement): ?UserMessage
    {
        return $this->sendSystem(
            $userId,
            UserMessageKind::StatementOfReasons,
            self::STATEMENT_CHANNEL,
            $refId,
            mb_substr($statement->reference, 0, 220),
            'messages.body.statement_of_reasons',
            ['%title%' => $statement->subject ?? ''],
            null,
            $statement,
        );
    }

    /**
     * Free-form curator note; flushes immediately. Null if the recipient is gone.
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
     * Rider needs-info reply, delivered to the curator. Null if that account is gone.
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
     * Thread heads for the reader (sent + received). Rider replies are attached by the controller.
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
            // Recipient-only, matching unreadCount().
            $qb->andWhere('m.readAt IS NULL')->andWhere('m.userId = :id');
        }

        /** @var list<UserMessage> $messages */
        $messages = $qb
            ->andWhere($qb->expr()->orX('m.userId = :id', 'm.senderId = :id'))
            // Rider replies stay on the desk; the controller re-attaches the reader's own.
            ->andWhere('m.kind != :moderationReply')
            // A thread whose row is in Trash is in no inbox (moderation-and-contribution.md §6).
            ->andWhere('m.trashedAt IS NULL')
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

    /** Thread-head count matching {@see self::listFor()} filters. */
    public function countFor(int $userId, ?MessageCategory $category = null, bool $unreadOnly = false): int
    {
        $sql = 'SELECT COUNT(*) FROM user_message
                 WHERE (user_id = :u OR sender_id = :u) AND kind <> :moderationReply AND trashed_at IS NULL';
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
     * Per-shelf totals plus unread. Unread is recipient-only; totals match the unfiltered list.
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
              WHERE (user_id = :u OR sender_id = :u) AND kind <> :moderationReply AND trashed_at IS NULL
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
            if (isset($shelfOf[$row['kind']])) {
                $byCategory[$shelfOf[$row['kind']]] += (int) $row['n'];
            }
        }

        return ['total' => $total, 'unread' => $unread, 'byCategory' => $byCategory];
    }

    /**
     * The reader's own needs-info replies for these submissions, oldest first.
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
            ->andWhere('m.trashedAt IS NULL')
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
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM user_message
             WHERE user_id = :u AND read_at IS NULL AND kind <> :moderationReply AND trashed_at IS NULL',
            ['u' => $userId, 'moderationReply' => UserMessageKind::RiderReply->value],
        );
    }

    public function markAllRead(int $userId): void
    {
        $this->db->executeStatement(
            'UPDATE user_message SET read_at = now()
             WHERE user_id = :u AND read_at IS NULL AND kind <> :moderationReply AND trashed_at IS NULL',
            ['u' => $userId, 'moderationReply' => UserMessageKind::RiderReply->value],
        );
    }

    /**
     * Mark these messages read, only those addressed to the reader.
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
             WHERE user_id = :u AND read_at IS NULL AND trashed_at IS NULL AND id IN (:ids)',
            ['u' => $userId, 'ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        );
    }

    /**
     * The reader opened one message: expanded it on the messages page, or
     * followed its own link to what it is about. Null when it is not addressed
     * to them (their own sent message, somebody else's, or gone); false when
     * it was already read; true when it came off the unread count.
     *
     * @see docs/specs/moderation-and-contribution.md §7.5a
     */
    public function markOpened(int $userId, int $id): ?bool
    {
        $recipient = $this->db->fetchOne('SELECT user_id FROM user_message WHERE id = :id AND trashed_at IS NULL', ['id' => $id]);
        if (false === $recipient || (int) $recipient !== $userId) {
            return null;
        }

        return 1 === (int) $this->db->executeStatement(
            'UPDATE user_message SET read_at = now() WHERE id = :id AND user_id = :u AND read_at IS NULL',
            ['id' => $id, 'u' => $userId],
        );
    }

    /**
     * Take one thread out of every inbox while the row it is about sits in
     * Trash: each message on this channel about this reference, whoever sent
     * or received it, stays stored and is shown nowhere but the Trash list.
     * Runs inside the caller's transaction.
     *
     * @see docs/specs/moderation-and-contribution.md §6
     *
     * @return int messages hidden
     */
    public function trashThread(string $channel, int $refId, \DateTimeImmutable $at): int
    {
        return (int) $this->db->executeStatement(
            'UPDATE user_message SET trashed_at = :at WHERE channel = :channel AND ref_id = :ref AND trashed_at IS NULL',
            ['at' => $at->format('Y-m-d H:i:s'), 'channel' => $channel, 'ref' => $refId],
        );
    }

    /**
     * Put a restored row's thread back where it was, read state and all.
     *
     * @return int messages shown again
     */
    public function restoreThread(string $channel, int $refId): int
    {
        return (int) $this->db->executeStatement(
            'UPDATE user_message SET trashed_at = NULL WHERE channel = :channel AND ref_id = :ref AND trashed_at IS NOT NULL',
            ['channel' => $channel, 'ref' => $refId],
        );
    }

    /**
     * Delete one thread from every inbox: each message on this channel about
     * this reference, whoever sent or received it. Runs inside the caller's
     * transaction.
     *
     * @return int messages deleted
     */
    public function deleteThread(string $channel, int $refId): int
    {
        return (int) $this->db->executeStatement(
            'DELETE FROM user_message WHERE channel = :channel AND ref_id = :ref',
            ['channel' => $channel, 'ref' => $refId],
        );
    }

    /** Deleted-recipient guard for every send* path. */
    private function recipientExists(int $userId): bool
    {
        return false !== $this->db->fetchOne('SELECT 1 FROM users WHERE id = :id', ['id' => $userId]);
    }

    /**
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
