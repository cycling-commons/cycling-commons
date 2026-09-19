<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Messaging;

use App\Messaging\Entity\CuratorPost;
use App\Messaging\Entity\CuratorPostImage;
use App\Messaging\Entity\CuratorRoomVisit;
use App\Moderation\DeskRider;
use App\Support\StoredImage;
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
 *     author: array{name: string, uuid: string|null}|null,
 *     category: string|null,
 *     recipient_id: int|null,
 *     recipient: array{name: string, uuid: string|null}|null,
 *     pin: string,
 *     body: string,
 *     about_submission_id: int|null,
 *     about_title: string|null,
 *     images: list<array{id: int, width: int, height: int}>,
 *     created_at: \DateTimeImmutable,
 *     edited_at: \DateTimeImmutable|null,
 *     mine: bool,
 *     direct: bool
 * }
 *
 * @psalm-type RoomCard = array{
 *     id: int,
 *     author_id: int|null,
 *     author: array{name: string, uuid: string|null}|null,
 *     category: string|null,
 *     recipient_id: int|null,
 *     recipient: array{name: string, uuid: string|null}|null,
 *     pin: string,
 *     body: string,
 *     about_submission_id: int|null,
 *     about_title: string|null,
 *     images: list<array{id: int, width: int, height: int}>,
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
    private const string ERROR_UNKNOWN_SUBMISSION = 'room.error.unknown_submission';

    /** Cards the composer's search lists. Enough to pick from, not a queue. */
    public const int SEARCH_LIMIT = 8;

    private const string ERROR_IMAGE_MISSING = 'room.error.image_missing';

    /** An uploaded picture nobody posted: gone after this. */
    private const string UNCLAIMED_TTL = '-1 day';

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
     * Pictures arrive two ways: `$imageIds` name uploads this author made
     * through {@see upload()} and not yet posted (the composer with its
     * script); `$images` are files rendered at post time (the composer
     * without it). Both end up as the post's images, uploads first.
     *
     * @param list<int>         $imageIds
     * @param list<StoredImage> $images
     *
     * @throws \InvalidArgumentException with a translation key, for the flash
     */
    public function post(
        int $authorId,
        ?CuratorRoomCategory $category,
        ?int $recipientId,
        string $body,
        ?int $aboutSubmissionId = null,
        array $images = [],
        array $imageIds = [],
        CuratorRoomPin $pin = CuratorRoomPin::None,
    ): CuratorPost {
        $body = $this->normalizeBody($body);
        $this->checkAddressing($recipientId, $aboutSubmissionId, $pin);

        $post = new CuratorPost($authorId, $category, $recipientId, $body, $aboutSubmissionId);
        $post->setPin($pin);
        $this->attachImages($post, $authorId, $imageIds, $images);
        $this->em->persist($post);
        $this->em->flush();

        return $post;
    }

    /**
     * The author changes any field of their own post. `$dropImageIds` names
     * pictures to take off it; `$imageIds` and `$images` add, as on post().
     *
     * @param list<int>         $imageIds
     * @param list<StoredImage> $images
     * @param list<int>         $dropImageIds
     *
     * @throws \InvalidArgumentException with a translation key, for the flash
     */
    public function edit(
        int $postId,
        int $authorId,
        ?CuratorRoomCategory $category,
        ?int $recipientId,
        string $body,
        ?int $aboutSubmissionId,
        CuratorRoomPin $pin,
        array $images = [],
        array $imageIds = [],
        array $dropImageIds = [],
    ): CuratorPost {
        $post = $this->own($postId, $authorId);
        if (!$post instanceof CuratorPost) {
            throw new \InvalidArgumentException('room.error.not_yours');
        }
        $body = $this->normalizeBody($body);
        $this->checkAddressing($recipientId, $aboutSubmissionId, $pin);

        foreach ($post->getImages()->toArray() as $image) {
            if (\in_array($image->getId(), $dropImageIds, true)) {
                $post->removeImage($image);
            }
        }
        $post->update($category, $recipientId, $body, $aboutSubmissionId, $pin);
        $this->attachImages($post, $authorId, $imageIds, $images);
        $this->em->flush();

        return $post;
    }

    /**
     * The author's own post, for the edit page. Null when it is not theirs.
     */
    public function own(int $postId, int $authorId): ?CuratorPost
    {
        $post = $this->em->getRepository(CuratorPost::class)->find($postId);

        return $post instanceof CuratorPost && $post->getAuthorId() === $authorId ? $post : null;
    }

    /**
     * @throws \InvalidArgumentException with a translation key
     */
    private function checkAddressing(?int $recipientId, ?int $aboutSubmissionId, CuratorRoomPin $pin): void
    {
        if (null !== $recipientId && !$this->isCurator($recipientId)) {
            throw new \InvalidArgumentException(self::ERROR_UNKNOWN_RECIPIENT);
        }
        // The column is a foreign key: an id nobody typed correctly would
        // otherwise fail at the database, as a 500 instead of a sentence.
        if (null !== $aboutSubmissionId && !$this->submissionExists($aboutSubmissionId)) {
            throw new \InvalidArgumentException(self::ERROR_UNKNOWN_SUBMISSION);
        }
        // A two-person note has no business at the top of the board (§13.5).
        if (null !== $recipientId && $pin->isPinned()) {
            throw new \InvalidArgumentException(self::ERROR_PIN_DIRECT);
        }
    }

    /**
     * @param list<int>         $imageIds
     * @param list<StoredImage> $images
     *
     * @throws \InvalidArgumentException with a translation key
     */
    private function attachImages(CuratorPost $post, int $authorId, array $imageIds, array $images): void
    {
        foreach (array_values(array_unique($imageIds)) as $id) {
            $upload = $this->em->getRepository(CuratorPostImage::class)->find($id);
            // Somebody else's upload, or one already on a post, is not this
            // author's to attach: refused as missing, never silently skipped.
            if (!$upload instanceof CuratorPostImage || $upload->isClaimed() || $upload->getUploaderId() !== $authorId) {
                throw new \InvalidArgumentException(self::ERROR_IMAGE_MISSING);
            }
            $post->addImage($upload);
        }
        foreach ($images as $image) {
            $post->addImage(new CuratorPostImage($image, $authorId));
        }
    }

    /**
     * Hold a rendered picture for a post this curator has not written yet.
     */
    public function upload(int $uploaderId, StoredImage $image): CuratorPostImage
    {
        $upload = new CuratorPostImage($image, $uploaderId);
        $this->em->persist($upload);
        $this->em->flush();

        return $upload;
    }

    /**
     * Drop a picture this curator uploaded and has not posted. False when it
     * is not theirs, already posted, or gone.
     */
    public function removeUnclaimed(int $imageId, int $uploaderId): bool
    {
        $upload = $this->em->getRepository(CuratorPostImage::class)->find($imageId);
        if (!$upload instanceof CuratorPostImage || $upload->isClaimed() || $upload->getUploaderId() !== $uploaderId) {
            return false;
        }
        $this->em->remove($upload);
        $this->em->flush();

        return true;
    }

    /**
     * Pictures uploaded and never posted, older than a day: gone. Run by
     * `app:media:gc` beside the photo pipeline's own sweep.
     */
    public function collectUnclaimedImages(): int
    {
        return (int) $this->db->executeStatement(
            'DELETE FROM curator_post_image WHERE post_id IS NULL AND created_at < :cutoff',
            ['cutoff' => (new \DateTimeImmutable(self::UNCLAIMED_TTL))->format('Y-m-d H:i:s')],
        );
    }

    /**
     * One image, if this reader may see the post it is on: a direct post shows
     * its pictures to its two people and nobody else (§13.6).
     */
    public function image(int $imageId, int $readerId): ?CuratorPostImage
    {
        $image = $this->em->getRepository(CuratorPostImage::class)->find($imageId);
        if (!$image instanceof CuratorPostImage) {
            return null;
        }
        $post = $image->getPost();
        // Not posted yet: only its uploader sees it, in their own composer.
        if (null === $post) {
            return $image->getUploaderId() === $readerId ? $image : null;
        }
        if ($post->isDirect() && $post->getAuthorId() !== $readerId && $post->getRecipientId() !== $readerId) {
            return null;
        }

        return $image;
    }

    /**
     * Submissions matching what a curator typed into the composer: an id, a
     * word of the title, or a region name. Unscoped, like the room: the card
     * out of your reach is the one you came here to ask about.
     *
     * @return list<array{id: int, title: string, type: string, status: string, region: string|null, country: string}>
     */
    public function searchSubmissions(string $q): array
    {
        $q = trim($q);
        if ('' === $q) {
            return [];
        }
        // A number matches every id that starts with it: "11" lists 11, 118, 1103.
        $idPrefix = preg_match('/^(?:SUB-?)?(\d{1,12})$/i', $q, $m) ? $m[1] : null;
        $rows = $this->db->fetchAllAssociative(
            <<<'SQL'
                SELECT s.id, s.title, s.type, s.status, s.country_code, r.name AS region
                FROM submission s
                LEFT JOIN region r ON r.id = s.region_id
                WHERE (:idp::text IS NOT NULL AND s.id::text LIKE :idp || '%')
                   OR s.title ILIKE :like OR r.name ILIKE :like
                ORDER BY (s.id::text = :idp) DESC, (s.status = 'pending') DESC, s.id DESC
                LIMIT :lim
                SQL,
            ['idp' => $idPrefix, 'like' => '%'.addcslashes($q, '%_\\').'%', 'lim' => self::SEARCH_LIMIT],
            ['lim' => \Doctrine\DBAL\ParameterType::INTEGER],
        );

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'title' => (string) $r['title'],
            'type' => (string) $r['type'],
            'status' => (string) $r['status'],
            'region' => null !== $r['region'] ? (string) $r['region'] : null,
            'country' => (string) $r['country_code'],
        ], $rows);
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
        return 'SELECT p.id, p.author_id, a.display_name AS author_name, a.public_profile AS author_public, a.uuid AS author_uuid, p.category,
                       p.recipient_id, r.display_name AS recipient_name, r.public_profile AS recipient_public, r.uuid AS recipient_uuid, p.pin, p.body,
                       p.about_submission_id, s.title AS about_title, p.created_at, p.edited_at
                FROM curator_post p
                LEFT JOIN users a ON a.id = p.author_id
                LEFT JOIN users r ON r.id = p.recipient_id
                LEFT JOIN submission s ON s.id = p.about_submission_id
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
        if ([] === $cards) {
            return $cards;
        }

        // One query for every card's pictures: id and size only, the bytes
        // stay in the database until an <img> asks for them.
        $ids = array_map(static fn (array $c): int => $c['id'], $cards);
        $images = $this->db->fetchAllAssociative(
            'SELECT id, post_id, width, height FROM curator_post_image WHERE post_id IN (:ids) ORDER BY post_id, position',
            ['ids' => $ids],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
        );
        $byPost = [];
        foreach ($images as $i) {
            $byPost[(int) $i['post_id']][] = ['id' => (int) $i['id'], 'width' => (int) $i['width'], 'height' => (int) $i['height']];
        }
        foreach ($cards as $k => $card) {
            $cards[$k]['images'] = $byPost[$card['id']] ?? [];
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
            // Curators name each other; the name links to a public profile (DeskRider::colleague).
            'author' => DeskRider::colleague($row['author_name'], $row['author_public'], $row['author_uuid']),
            'category' => null !== $row['category'] ? (string) $row['category'] : null,
            'recipient_id' => $recipientId,
            'recipient' => DeskRider::colleague($row['recipient_name'], $row['recipient_public'], $row['recipient_uuid']),
            'pin' => (string) $row['pin'],
            'body' => (string) $row['body'],
            'about_submission_id' => null !== $row['about_submission_id'] ? (int) $row['about_submission_id'] : null,
            'about_title' => null !== $row['about_title'] ? (string) $row['about_title'] : null,
            'images' => [],
            'created_at' => new \DateTimeImmutable((string) $row['created_at']),
            'edited_at' => null !== $row['edited_at'] ? new \DateTimeImmutable((string) $row['edited_at']) : null,
            'mine' => null !== $authorId && $authorId === $readerId,
            'direct' => null !== $recipientId,
        ];
    }

    /** The title the edit page shows in the about chip. */
    public function submissionTitle(int $id): ?string
    {
        $t = $this->db->fetchOne('SELECT title FROM submission WHERE id = :id', ['id' => $id]);

        return false === $t ? null : (string) $t;
    }

    private function submissionExists(int $id): bool
    {
        return false !== $this->db->fetchOne('SELECT 1 FROM submission WHERE id = :id', ['id' => $id]);
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
