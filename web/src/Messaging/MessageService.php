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
 * System outcomes, curator notes, and rider replies — plus dashboard reads.
 *
 * @see docs/specs/moderation-and-contribution.md §7
 *
 * @api
 */
final class MessageService
{
    public const int PER_PAGE = 20;

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
             WHERE user_id = :u AND read_at IS NULL AND kind <> :moderationReply',
            ['u' => $userId, 'moderationReply' => UserMessageKind::RiderReply->value],
        );
    }

    public function markAllRead(int $userId): void
    {
        $this->db->executeStatement(
            'UPDATE user_message SET read_at = now()
             WHERE user_id = :u AND read_at IS NULL AND kind <> :moderationReply',
            ['u' => $userId, 'moderationReply' => UserMessageKind::RiderReply->value],
        );
    }

    /**
     * Mark read only the rendered rows, and only those addressed to the reader.
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
