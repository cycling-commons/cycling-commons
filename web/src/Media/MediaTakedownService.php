<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media;

use App\Catalog\Entity\Item;
use App\Entity\User;
use App\Media\Entity\MediaUpload;
use App\Messaging\MessageService;
use App\Messaging\UserMessageKind;
use App\Security\PseudonymousKey;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Uploader takedown vs third-party report vs illegal-content hold.
 *
 * @see docs/specs/photo-uploads.md §6b, §6c, §6d
 *
 * @api
 */
final class MediaTakedownService
{
    public const int REASON_MAX = 2000;

    /** Cards per page on the takedown desk and the withheld-photo recovery page. */
    public const int PER_PAGE = 25;

    /** Open requests, excluding legal hold. @see docs/specs/photo-uploads.md §6d */
    /**
     * The takedown desk's own queue: what an UPLOADER asked us to remove.
     *
     * Third-party reports left this desk on 2026-08-30 and are decided at
     * /moderate/reports instead, where the DSA record lives and where deciding
     * also sends the reporter their Article 16(5) outcome and the author their
     * Article 17 statement. They still raise a takedown request here, which is
     * what a decision over there then grants or declines; what changed is which
     * desk holds the button, because two desks that can both decide the same
     * row is exactly how a reporter ends up never hearing back.
     *
     * @see docs/specs/2026-08-30-one-report-route-design.md §3
     */
    private const string PENDING_DQL = "m.takedownRequestedAt IS NOT NULL AND m.objectsDeletedAt IS NULL
               AND m.escalatedAt IS NULL AND m.takedownSource = 'uploader'";

    /** Recovery desk: withheld third-party reports still waiting. */
    private const string WITHHELD_THIRD_PARTY_DQL = 'm.takedownRequestedAt IS NOT NULL AND m.objectsDeletedAt IS NULL
               AND m.takedownSource = :source AND m.takedownWithheld = true
               AND m.escalatedAt IS NULL';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
        private readonly MediaEventLog $events,
        private readonly MediaDisposalService $disposal,
        private readonly MediaDecisionService $decisions,
        private readonly MessageService $messages,
        private readonly UrgentWithholdBreaker $breaker,
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
    ) {
    }

    /**
     * Uploader request: withhold immediately.
     *
     * @see docs/specs/photo-uploads.md §6b
     *
     * @throws \InvalidArgumentException when the reason is empty or over-long
     */
    public function request(MediaUpload $upload, string $reason): void
    {
        $reason = trim($reason);
        if ('' === $reason) {
            throw new \InvalidArgumentException('media.takedown.error.reason_required');
        }
        if (mb_strlen($reason) > self::REASON_MAX) {
            throw new \InvalidArgumentException('media.takedown.error.reason_too_long');
        }

        $upload->requestTakedown($reason);
        $this->detach($upload);
        $this->events->append($upload->getId(), $upload->getUserId(), MediaAction::TakedownRequested, $reason);
        $this->em->flush();
    }

    /**
     * Third-party report: queue unless urgent auto-withhold.
     *
     * @see docs/specs/photo-uploads.md §6c
     */
    public function report(MediaUpload $upload, string $category, string $reason, ?string $contact, string $reporterIp): void
    {
        if (!MediaTakedownCategory::isValid($category)) {
            throw new \InvalidArgumentException('media.report.error.category');
        }
        $reason = trim($reason);
        if ('' === $reason) {
            throw new \InvalidArgumentException('media.takedown.error.reason_required');
        }
        if (mb_strlen($reason) > self::REASON_MAX) {
            throw new \InvalidArgumentException('media.takedown.error.reason_too_long');
        }

        if (MediaStatus::Approved !== $upload->getStatus()
            || null !== $upload->getObjectsDeletedAt()
            || $upload->isTakedownPending()
            || $upload->hasDecidedTakedown($category)
        ) {
            return;
        }

        $urgent = MediaTakedownCategory::autoWithholds($category);
        // Only a withhold-bound report spends the breaker budget.
        $withheld = $urgent && $this->breaker->allowWithhold();

        $upload->reportThirdParty($category, $reason, $contact, $this->hashReporter($reporterIp), $withheld);
        if ($withheld) {
            $this->detach($upload);
            $this->notify($upload, UserMessageKind::MediaHiddenPendingReview, 'messages.body.media_hidden_pending_review', null);
        }
        $this->events->append(
            $upload->getId(),
            null,
            MediaAction::ThirdPartyReported,
            $category.match (true) {
                $withheld => ' (auto-withheld)',
                $urgent => ' (queued — breaker open)',
                default => '',
            },
        );
        $this->em->flush();
    }

    /** Keyed reporter-IP hash: volume, not identity. @see \App\Security\PseudonymousKey */
    private function hashReporter(string $ip): string
    {
        return PseudonymousKey::of('media-report', $ip, $this->secret);
    }

    /**
     * Grant: delete objects, keep the row.
     *
     * @see docs/specs/photo-uploads.md §6b
     */
    public function grant(MediaUpload $upload, User $curator, ?string $note = null): void
    {
        // Legal hold: only an admin may act; grant would destroy preserved objects. @see docs/specs/photo-uploads.md §6d
        if (!$upload->isTakedownPending() || $upload->isEscalated()) {
            return;
        }

        $thirdParty = MediaTakedownSource::ThirdParty === $upload->getTakedownSource();
        $this->detach($upload);
        $upload->resolveTakedown();
        $this->events->append($upload->getId(), (int) $curator->getId(), MediaAction::TakedownGranted, $note);
        $this->disposal->deleteObjects($upload);
        $this->notify(
            $upload,
            $thirdParty ? UserMessageKind::MediaRemovedOnReport : UserMessageKind::MediaTakedownGranted,
            $thirdParty ? 'messages.body.media_removed_on_report' : 'messages.body.media_takedown_granted',
            $note,
        );
        $this->em->flush();
    }

    /**
     * Decline: republish; third-party category is final.
     *
     * @see docs/specs/photo-uploads.md §6c
     */
    public function decline(MediaUpload $upload, User $curator, ?string $note = null): void
    {
        if (!$upload->isTakedownPending() || $upload->isEscalated()) {
            return;
        }

        $thirdParty = MediaTakedownSource::ThirdParty === $upload->getTakedownSource();
        $wasHidden = $upload->isTakedownWithheld();
        $upload->declineTakedown();
        $this->reattach($upload);
        $this->events->append($upload->getId(), (int) $curator->getId(), MediaAction::TakedownDeclined, $note);
        if (!$thirdParty) {
            $this->notify($upload, UserMessageKind::MediaTakedownDeclined, 'messages.body.media_takedown_declined', $note);
        } elseif ($wasHidden) {
            $this->notify($upload, UserMessageKind::MediaRestoredAfterReview, 'messages.body.media_restored_after_review', null);
        }
        $this->em->flush();
    }

    /**
     * Abusive-report undo: republish without closing the category.
     *
     * @see docs/specs/photo-uploads.md §6c
     *
     * @return bool whether anything was restored — false for a row somebody else already decided
     */
    public function dismissAsAbuse(MediaUpload $upload, User $admin, ?string $note = null): bool
    {
        if (!$upload->isTakedownPending()
            || MediaTakedownSource::ThirdParty !== $upload->getTakedownSource()
            || $upload->isEscalated()) {
            return false;
        }

        $wasHidden = $upload->isTakedownWithheld();
        $upload->dismissTakedownAsAbuse();
        $this->reattach($upload);
        $this->events->append($upload->getId(), (int) $admin->getId(), MediaAction::TakedownDismissedAsAbuse, $note);
        if ($wasHidden) {
            $this->notify($upload, UserMessageKind::MediaRestoredAfterReview, 'messages.body.media_restored_after_review', null);
        }
        $this->em->flush();

        return true;
    }

    /**
     * Recovery desk: withheld third-party reports, newest first.
     *
     * @return list<array{uuid: string, sm: string, reason: string, requestedAt: \DateTimeImmutable, itemName: string, category: ?string, reporter: string}>
     */
    public function withheldThirdPartyCards(int $page = 1, int $perPage = self::PER_PAGE): array
    {
        /** @var list<MediaUpload> $rows */
        $rows = $this->em->createQuery(
            'SELECT m FROM '.MediaUpload::class.' m
             WHERE '.self::WITHHELD_THIRD_PARTY_DQL.'
             ORDER BY m.takedownRequestedAt DESC',
        )
            ->setParameter('source', MediaTakedownSource::ThirdParty)
            ->setFirstResult(self::offset($page, $perPage))
            ->setMaxResults(max(1, $perPage))
            ->getResult();

        $cards = [];
        foreach ($rows as $upload) {
            $requestedAt = $upload->getTakedownRequestedAt();
            if (null === $requestedAt) {
                continue;
            }
            $cards[] = [
                'uuid' => $upload->getId()->toRfc4122(),
                'sm' => (string) $this->decisions->describe($upload)['sm'],
                'reason' => $upload->getTakedownReason() ?? '',
                'requestedAt' => $requestedAt,
                'itemName' => $this->item($upload)?->getName() ?? '',
                'category' => $upload->getTakedownCategory(),
                'reporter' => substr($upload->getTakedownReporterHash() ?? '', 0, 8),
            ];
        }

        return $cards;
    }

    /** How many photos the recovery desk is holding, for its pager. */
    public function withheldThirdPartyCount(): int
    {
        return (int) $this->em->createQuery(
            'SELECT COUNT(m.id) FROM '.MediaUpload::class.' m WHERE '.self::WITHHELD_THIRD_PARTY_DQL,
        )->setParameter('source', MediaTakedownSource::ThirdParty)->getSingleScalarResult();
    }

    /**
     * Drop reporter contacts 90 days after resolution.
     *
     * @see docs/specs/photo-uploads.md §6c
     *
     * @return int rows cleared
     */
    public function purgeExpiredContacts(\DateTimeImmutable $now): int
    {
        $cutoff = $now->modify('-90 days');

        /** @var list<MediaUpload> $rows */
        $rows = $this->em->createQuery(
            'SELECT m FROM '.MediaUpload::class.' m
             WHERE m.takedownContact IS NOT NULL AND m.takedownResolvedAt < :cutoff',
        )->setParameter('cutoff', $cutoff)->getResult();

        foreach ($rows as $upload) {
            $upload->clearTakedownContact();
        }
        $this->em->flush();

        return \count($rows);
    }

    /** @return list<MediaUpload> oldest first */
    public function pending(int $page = 1, int $perPage = self::PER_PAGE): array
    {
        /** @var list<MediaUpload> $rows */
        $rows = $this->em->createQuery(
            'SELECT m FROM '.MediaUpload::class.' m
             WHERE '.self::PENDING_DQL.'
             ORDER BY m.takedownRequestedAt ASC',
        )
            ->setFirstResult(self::offset($page, $perPage))
            ->setMaxResults(max(1, $perPage))
            ->getResult();

        return $rows;
    }

    /** Open rights-request count (pager and desk badge). */
    public function pendingCount(): int
    {
        return (int) $this->em->createQuery(
            'SELECT COUNT(m.id) FROM '.MediaUpload::class.' m WHERE '.self::PENDING_DQL,
        )->getSingleScalarResult();
    }

    private static function offset(int $page, int $perPage): int
    {
        return max(0, (max(1, $page) - 1) * max(1, $perPage));
    }

    private const array DECIDED_ACTIONS = [
        MediaAction::TakedownGranted,
        MediaAction::TakedownDeclined,
        MediaAction::TakedownDismissedAsAbuse,
    ];

    /**
     * Answered rights requests, newest first. Read from the event log.
     *
     * @return list<array{action: string, note: ?string, requestedAt: ?\DateTimeImmutable, decidedAt: \DateTimeImmutable, waitedHours: ?int, actor: ?string, uuid: string, itemName: string, gone: bool}>
     */
    public function decidedCards(int $page = 1, int $perPage = self::PER_PAGE): array
    {
        /** @var list<array{action: string, note: ?string, created_at: string, display_name: ?string, public_profile: ?bool, media_id: string, requested_at: ?string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            "SELECT e.action, e.note, e.created_at, e.media_id,
                    u.display_name, u.public_profile,
                    req.created_at AS requested_at
               FROM media_moderation_event e
          LEFT JOIN users u ON u.id = e.actor_id
          LEFT JOIN LATERAL (
                  SELECT r.created_at
                    FROM media_moderation_event r
                   WHERE r.media_id = e.media_id
                     AND r.action IN ('takedown_requested', 'third_party_reported')
                     AND r.created_at <= e.created_at
                ORDER BY r.created_at DESC, r.id DESC
                   LIMIT 1
               ) req ON TRUE
              WHERE e.action IN (:actions)
           ORDER BY e.created_at DESC, e.id DESC
              LIMIT :lim OFFSET :off",
            [
                'actions' => self::DECIDED_ACTIONS,
                'lim' => max(1, $perPage),
                'off' => self::offset($page, $perPage),
            ],
            ['actions' => ArrayParameterType::STRING],
        );

        $cards = [];
        foreach ($rows as $r) {
            $upload = $this->em->getRepository(MediaUpload::class)->find(Uuid::fromString((string) $r['media_id']));
            $requestedAt = null !== $r['requested_at']
                ? new \DateTimeImmutable((string) $r['requested_at'])
                : null;
            $decidedAt = new \DateTimeImmutable((string) $r['created_at']);
            $cards[] = [
                'action' => (string) $r['action'],
                'note' => null !== $r['note'] && '' !== $r['note'] ? (string) $r['note'] : null,
                'requestedAt' => $requestedAt,
                'decidedAt' => $decidedAt,
                'waitedHours' => null !== $requestedAt
                    ? max(0, (int) round(($decidedAt->getTimestamp() - $requestedAt->getTimestamp()) / 3600))
                    : null,
                'actor' => ($r['public_profile'] ?? false) ? (string) ($r['display_name'] ?? '') : null,
                'uuid' => (string) $r['media_id'],
                'itemName' => null !== $upload ? ($this->item($upload)?->getName() ?? '') : '',
                'gone' => null === $upload,
            ];
        }

        return $cards;
    }

    /** How many rights requests have been answered, for the pager. */
    public function decidedCount(): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM media_moderation_event WHERE action IN (:actions)',
            ['actions' => self::DECIDED_ACTIONS],
            ['actions' => ArrayParameterType::STRING],
        );
    }

    /**
     * Pending desk cards; not region-scoped.
     *
     * @return list<array{uuid: string, sm: string, reason: string, requestedAt: \DateTimeImmutable, itemName: string, source: string, category: ?string, contact: ?string, withheld: bool}>
     */
    public function pendingCards(int $page = 1, int $perPage = self::PER_PAGE): array
    {
        $cards = [];
        foreach ($this->pending($page, $perPage) as $upload) {
            $requestedAt = $upload->getTakedownRequestedAt();
            if (null === $requestedAt) {
                continue;
            }
            $cards[] = [
                'uuid' => $upload->getId()->toRfc4122(),
                'sm' => (string) $this->decisions->describe($upload)['sm'],
                'reason' => $upload->getTakedownReason() ?? '',
                'requestedAt' => $requestedAt,
                'itemName' => $this->item($upload)?->getName() ?? '',
                'source' => $upload->getTakedownSource() ?? MediaTakedownSource::Uploader,
                'category' => $upload->getTakedownCategory(),
                'contact' => $upload->getTakedownContact(),
                'withheld' => $upload->isTakedownWithheld(),
            ];
        }

        return $cards;
    }

    /** Drop the photo from the item's gallery, matched on the sm URL. */
    private function detach(MediaUpload $upload): void
    {
        $item = $this->item($upload);
        if (null === $item) {
            return;
        }

        $attributes = $item->getAttributes();
        $photos = $attributes['photos'] ?? null;
        if (!\is_array($photos)) {
            return;
        }

        $kept = array_values(array_filter(
            $photos,
            fn (mixed $photo): bool => !$this->decisions->isEntryFor($photo, $upload),
        ));

        if ([] === $kept) {
            // Drop the key: map.js reads f.photos || [f.photo], and [] is truthy.
            unset($attributes['photos']);
        } else {
            $attributes['photos'] = $kept;
        }
        $item->setAttributes($attributes);
    }

    /**
     * Reattach in the shape approval originally wrote, when PhotoValidator
     * still links it to the item (`show` or `hide`). Matched by upload id
     * (MediaDecisionService::isEntryFor()), so it is never added twice.
     */
    private function reattach(MediaUpload $upload): void
    {
        $item = $this->item($upload);
        if (null === $item) {
            return;
        }
        if (!PhotoValidator::verdict(PhotoFacts::ofUpload($upload), new PhotoPlace($item->getLetter(), null, null))->links()) {
            return;
        }

        $entry = $this->decisions->describe($upload);
        $attributes = $item->getAttributes();
        $photos = $attributes['photos'] ?? null;
        $gallery = \is_array($photos) ? array_values(array_filter($photos, is_array(...))) : [];

        foreach ($gallery as $photo) {
            if ($this->decisions->isEntryFor($photo, $upload)) {
                return;
            }
        }

        $gallery[] = $entry;
        $attributes['photos'] = $gallery;
        $item->setAttributes($attributes);
    }

    private function item(MediaUpload $upload): ?Item
    {
        $itemId = $upload->getItemId();

        return null !== $itemId ? $this->em->find(Item::class, $itemId) : null;
    }

    /** Notify the uploader; no-op if the account is already gone. */
    private function notify(MediaUpload $upload, UserMessageKind $kind, string $bodyKey, ?string $note): void
    {
        $userId = $upload->getUserId();
        if (null === $userId) {
            return;
        }

        $item = $this->item($upload);
        $this->messages->sendSystem(
            $userId,
            $kind,
            'media',
            $upload->getItemId() ?? 0,
            $item?->getName() ?? '',
            $bodyKey,
            [],
            $note,
        );
    }
}
