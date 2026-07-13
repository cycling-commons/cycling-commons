<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Messaging;

use App\Messaging\Entity\UserMessage;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The single owner of message writes and reads (moderation-feedback spec
 * M1/M2): system decision outcomes, free-form curator notes, and rider
 * needs-info replies all funnel through here, plus the dashboard's list/
 * unread-count/mark-read reads.
 *
 * @api Called by moderation decision handlers, the curator message action,
 *      the rider reply action, and the messages dashboard (Tasks 3-9).
 */
final class MessageService
{
    private const int BODY_TEXT_MAX_LENGTH = 2000;
    private const string ERROR_TOO_LONG = 'moderate.error.note_too_long';
    private const string ERROR_REQUIRED = 'moderate.error.note_required';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
    ) {
    }

    /**
     * Records a system decision outcome. Persists WITHOUT flushing: callers
     * invoke this from inside a decision transaction (e.g. ModerationService
     * ::decide's `wrapInTransaction` closure) whose own flush-on-commit
     * writes this row alongside the decision — an extra flush here would be
     * redundant and would risk flushing a still-inconsistent unit of work.
     *
     * `$curatorNote`, when non-empty after trimming, rides along in
     * `body_text` next to the translated `body_key` headline so the rider
     * sees the curator's note under it.
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
    ): UserMessage {
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

        return $message;
    }

    /**
     * Records a free-form curator note to a rider. Standalone action (not
     * inside a decision transaction) — persists and flushes immediately.
     *
     * @throws \InvalidArgumentException if the trimmed body is empty or exceeds 2000 characters
     */
    public function sendCurator(int $userId, int $curatorId, string $channel, int $refId, string $refLabel, string $bodyText): UserMessage
    {
        $body = $this->normalizeBody($bodyText, false);

        $message = new UserMessage($userId, UserMessageKind::CuratorMessage, 'curator', $curatorId, $channel, $refId, $refLabel, null, null, $body);
        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    /**
     * Records a rider's needs-info reply, delivered TO the curator (the
     * message recipient is the curator, not the rider). Standalone action —
     * persists and flushes immediately.
     *
     * @throws \InvalidArgumentException if the trimmed body is empty or exceeds 2000 characters
     */
    public function sendRiderReply(int $recipientCuratorId, int $riderId, string $channel, int $refId, string $refLabel, string $bodyText): UserMessage
    {
        $body = $this->normalizeBody($bodyText, false);

        $message = new UserMessage($recipientCuratorId, UserMessageKind::RiderReply, 'rider', $riderId, $channel, $refId, $refLabel, null, null, $body);
        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    /** @return list<UserMessage> */
    public function listFor(int $userId, int $limit = 100): array
    {
        $messages = $this->em->getRepository(UserMessage::class)->findBy(
            ['userId' => $userId],
            ['createdAt' => 'DESC', 'id' => 'DESC'],
            $limit,
        );

        return $messages;
    }

    public function unreadCount(int $userId): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM user_message WHERE user_id = :u AND read_at IS NULL',
            ['u' => $userId],
        );
    }

    public function markAllRead(int $userId): void
    {
        $this->db->executeStatement(
            'UPDATE user_message SET read_at = now() WHERE user_id = :u AND read_at IS NULL',
            ['u' => $userId],
        );
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
