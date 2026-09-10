<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Messaging;

use App\Messaging\Entity\CuratorPost;
use App\Messaging\Entity\CuratorRoomVisit;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The curator room: reads for the board, writes for the composer.
 *
 * Unscoped by design. Every other moderation query narrows by
 * `ModerationScope`; this one must not, because "if an item is out of reach,
 * ask the room" only works if the room reaches past the asker's area. There is
 * no region column on `curator_post` for a later query to filter on.
 *
 * @see docs/specs/moderation-and-contribution.md §13
 *
 * @phpstan-type RoomCard array{
 *     id: int,
 *     author_id: int|null,
 *     author_name: string|null,
 *     category: string|null,
 *     recipient_id: int|null,
 *     recipient_name: string|null,
 *     pin: string,
 *     body: string,
 *     about_submission_id: int|null,
 *     created_at: \DateTimeImmutable,
 *     edited_at: \DateTimeImmutable|null,
 *     mine: bool,
 *     direct: bool
 * }
 *
 * @psalm-type RoomCard = array{
 *     id: int,
 *     author_id: int|null,
 *     author_name: string|null,
 *     category: string|null,
 *     recipient_id: int|null,
 *     recipient_name: string|null,
 *     pin: string,
 *     body: string,
 *     about_submission_id: int|null,
 *     created_at: \DateTimeImmutable,
 *     edited_at: \DateTimeImmutable|null,
 *     mine: bool,
 *     direct: bool
 * }
 *
 * @api
 */
final class CuratorRoom
{
    public const int BODY_MAX_LENGTH = 2000;
    public const int PER_PAGE = 50;

    private const string ERROR_REQUIRED = 'room.error.body_required';
    private const string ERROR_TOO_LONG = 'room.error.body_too_long';
    private const string ERROR_UNKNOWN_RECIPIENT = 'room.error.unknown_recipient';
    private const string ERROR_PIN_DIRECT = 'room.error.pin_direct';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
    ) {
    }

    /**
     * The board as one view: what is pinned here, then the rest, newest first.
     *
     * `$view` is a category value, `direct`, `root`, or anything else for All.
     *
     * @return array{pinned: list<RoomCard>, posts: list<RoomCard>}
     */
    public function board(int $readerId, string $view): array
    {
        $category = CuratorRoomCategory::tryFromView($view);
        $direct = CuratorRoomCategory::VIEW_DIRECT === $view;
        $root = CuratorRoomCategory::VIEW_ROOT === $view;

        // A direct message is never pinned (§13.5), so the Direct view has no
        // pinned block at all.
        $pinned = $direct ? [] : $this->fetch($readerId, $category, $root, $direct, true);
        $posts = $this->fetch($readerId, $category, $root, $direct, false);

        return ['pinned' => $pinned, 'posts' => $posts];
    }

    /**
     * Write a post. `$recipientId` null addresses every curator.
     *
     * @throws \InvalidArgumentException with a translation key, for the flash
     */
    public function post(
        int $authorId,
        ?CuratorRoomCategory $category,
        ?int $recipientId,
        string $body,
        ?int $aboutSubmissionId = null,
    ): CuratorPost {
        $body = $this->normalizeBody($body);

        if (null !== $recipientId && !$this->isCurator($recipientId)) {
            throw new \InvalidArgumentException(self::ERROR_UNKNOWN_RECIPIENT);
        }

        $post = new CuratorPost($authorId, $category, $recipientId, $body, $aboutSubmissionId);
        $this->em->persist($post);
        $this->em->flush();

        return $post;
    }

    /**
     * Pin, unpin, or move a pin. Any curator may; there is no separate right.
     *
     * @throws \InvalidArgumentException with a translation key, for the flash
     */
    public function pin(int $postId, CuratorRoomPin $pin): void
    {
        $post = $this->em->getRepository(CuratorPost::class)->find($postId);
        if (!$post instanceof CuratorPost) {
            return;
        }

        // The form hides the control on a direct message; the server refuses it
        // again, because a two-person note has no business at the top of a
        // board the other curators read.
        if ($post->isDirect() && $pin->isPinned()) {
            throw new \InvalidArgumentException(self::ERROR_PIN_DIRECT);
        }

        $post->setPin($pin);
        $this->em->flush();
    }

    /**
     * Delete your own post. Returns false when it is not yours, or is gone.
     *
     * A hard delete with no tombstone: a staffroom note is not a moderation
     * record, and §8's keep-the-record principle covers decisions.
     */
    public function deleteOwn(int $postId, int $authorId): bool
    {
        $post = $this->em->getRepository(CuratorPost::class)->find($postId);
        if (!$post instanceof CuratorPost || $post->getAuthorId() !== $authorId) {
            return false;
        }

        $this->em->remove($post);
        $this->em->flush();

        return true;
    }

    /**
     * Posts this curator has not seen: not their own, addressed to them or to
     * everybody, since their last visit. Zero until they have visited once.
     */
    public function unreadCount(int $readerId): int
    {
        $sql = <<<'SQL'
            SELECT COUNT(*)
            FROM curator_post p
            JOIN curator_room_visit v ON v.user_id = :me
            WHERE p.created_at > v.last_seen_at
              AND (p.author_id IS NULL OR p.author_id <> :me)
              AND (p.recipient_id IS NULL OR p.recipient_id = :me)
            SQL;

        return (int) $this->db->fetchOne($sql, ['me' => $readerId]);
    }

    /**
     * Stamp this curator's visit. Called on every room load.
     */
    public function markSeen(int $readerId): void
    {
        $visit = $this->em->getRepository(CuratorRoomVisit::class)->find($readerId);
        if ($visit instanceof CuratorRoomVisit) {
            $visit->touch();
        } else {
            $this->em->persist(new CuratorRoomVisit($readerId));
        }
        $this->em->flush();
    }

    /**
     * Everyone who can read the room, for the composer's "to" list.
     *
     * @return list<array{id: int, name: string}>
     */
    public function curators(int $exceptId): array
    {
        $rows = $this->db->fetchAllAssociative(
            <<<'SQL'
                SELECT id, display_name
                FROM users
                WHERE id <> :me
                  AND (roles::jsonb @> '["ROLE_CURATOR"]'::jsonb OR roles::jsonb @> '["ROLE_ADMIN"]'::jsonb)
                ORDER BY display_name ASC
                SQL,
            ['me' => $exceptId],
        );

        return array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['display_name']],
            $rows,
        );
    }

    /**
     * @return list<RoomCard>
     */
    private function fetch(int $readerId, ?CuratorRoomCategory $category, bool $root, bool $direct, bool $pinnedOnly): array
    {
        $params = ['me' => $readerId];
        $where = ['(p.recipient_id IS NULL OR p.recipient_id = :me OR p.author_id = :me)'];

        if (null !== $category) {
            $params['cat'] = $category->value;
        }

        // Pinned "here" depends on the view: a room pin floats in every view, a
        // category pin only inside its own category (§13.5).
        $pinHere = null !== $category
            ? "(p.pin = 'room' OR (p.pin = 'category' AND p.category = :cat))"
            : "p.pin = 'room'";

        if ($pinnedOnly) {
            // No category clause: a post pinned to the room floats above views
            // it does not belong to. That is what pinning to the room means.
            $where[] = $pinHere;

            $sql = $this->select($where);

            return $this->cards($sql, $params, $readerId);
        }

        if ($direct) {
            $where[] = 'p.recipient_id IS NOT NULL';
        } elseif ($root) {
            $where[] = 'p.category IS NULL';
        } elseif (null !== $category) {
            $where[] = 'p.category = :cat';
        }

        $where[] = 'NOT '.$pinHere;

        return $this->cards($this->select($where), $params, $readerId);
    }

    /**
     * @param list<string> $where
     */
    private function select(array $where): string
    {
        return 'SELECT p.id, p.author_id, a.display_name AS author_name, p.category,
                       p.recipient_id, r.display_name AS recipient_name, p.pin, p.body,
                       p.about_submission_id, p.created_at, p.edited_at
                FROM curator_post p
                LEFT JOIN users a ON a.id = p.author_id
                LEFT JOIN users r ON r.id = p.recipient_id
                WHERE '.implode(' AND ', $where).'
                ORDER BY p.created_at DESC
                LIMIT '.self::PER_PAGE;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return list<RoomCard>
     */
    private function cards(string $sql, array $params, int $readerId): array
    {
        $cards = [];
        foreach ($this->db->fetchAllAssociative($sql, $params) as $row) {
            $cards[] = $this->card($row, $readerId);
        }

        return $cards;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return RoomCard
     */
    private function card(array $row, int $readerId): array
    {
        $authorId = null !== $row['author_id'] ? (int) $row['author_id'] : null;
        $recipientId = null !== $row['recipient_id'] ? (int) $row['recipient_id'] : null;

        return [
            'id' => (int) $row['id'],
            'author_id' => $authorId,
            'author_name' => null !== $row['author_name'] ? (string) $row['author_name'] : null,
            'category' => null !== $row['category'] ? (string) $row['category'] : null,
            'recipient_id' => $recipientId,
            'recipient_name' => null !== $row['recipient_name'] ? (string) $row['recipient_name'] : null,
            'pin' => (string) $row['pin'],
            'body' => (string) $row['body'],
            'about_submission_id' => null !== $row['about_submission_id'] ? (int) $row['about_submission_id'] : null,
            'created_at' => new \DateTimeImmutable((string) $row['created_at']),
            'edited_at' => null !== $row['edited_at'] ? new \DateTimeImmutable((string) $row['edited_at']) : null,
            'mine' => null !== $authorId && $authorId === $readerId,
            'direct' => null !== $recipientId,
        ];
    }

    private function isCurator(int $userId): bool
    {
        $sql = <<<'SQL'
            SELECT 1 FROM users
            WHERE id = :id AND (roles::jsonb @> '["ROLE_CURATOR"]'::jsonb OR roles::jsonb @> '["ROLE_ADMIN"]'::jsonb)
            SQL;

        return false !== $this->db->fetchOne($sql, ['id' => $userId]);
    }

    /**
     * @throws \InvalidArgumentException with a translation key, for the flash
     */
    private function normalizeBody(string $body): string
    {
        $body = trim($body);
        if ('' === $body) {
            throw new \InvalidArgumentException(self::ERROR_REQUIRED);
        }
        if (mb_strlen($body) > self::BODY_MAX_LENGTH) {
            throw new \InvalidArgumentException(self::ERROR_TOO_LONG);
        }

        return $body;
    }
}
