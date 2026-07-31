<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Messaging;

use App\Messaging\Entity\UserMessage;
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
