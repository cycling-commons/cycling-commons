<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media;

use App\Catalog\Entity\Item;
use App\Entity\User;
use App\Media\Entity\MediaUpload;
use App\Messaging\MessageService;
use App\Messaging\UserMessageKind;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * "That photo is of me — take it down" (docs/specs/photo-uploads.md §6b).
 *
 * Account deletion already answers most of what GDPR Art. 17 asks of this
 * domain: an approved photo is a CC BY-SA contribution to the commons, the
 * personal data in it is the LINK to a person, and severing the link — which
 * MediaDisposalService::anonymizeFor() does — leaves nothing for erasure to
 * reach. The licence itself is irrevocable (CC BY-SA 4.0 §2(a)(1)) and neither
 * side can undo it.
 *
 * One case that reasoning does not cover: when the image DEPICTS the uploader.
 * Then the image is itself their personal data, a copyright licence does not
 * waive data-protection rights, and the objects have to actually go. This is
 * the path for that.
 *
 * Why a curator sees it at all, when the law does not leave much discretion:
 * two different requests arrive through one door. "This is a photo of me" must
 * be honoured. "I have changed my mind about contributing" must not be, or the
 * commons is only on loan and every approved photo is provisional. Nothing but
 * the rider's own words distinguishes them, so a human reads them. What the
 * curator is deciding is which request this is — not whether to feel like
 * granting it.
 *
 * Meanwhile the photo comes down IMMEDIATELY, before anyone looks. GDPR
 * Art. 18 makes restriction available while a request is verified, and if the
 * claim is true then leaving it up until somebody gets round to it is the one
 * outcome with a real cost.
 *
 * No new desk and no new verbs: requests surface in the existing moderation
 * queue and are granted or declined there, which is the same promise made for
 * per-photo approval (docs/specs/photo-uploads.md §5c).
 *
 * @api Called by MediaTakedownController and ModerateController.
 */
final class MediaTakedownService
{
    public const int REASON_MAX = 2000;

    public function __construct(
        private readonly EntityManagerInterface $em,
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
     * The rider asks. Withholds the photo in the same transaction as the
     * request is recorded, so there is no window in which the request exists
     * and the photo is still on the map.
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
        // The uploader is the actor here, not a curator: this is the one entry
        // in the log written by the person the photo is about.
        $this->events->append($upload->getId(), $upload->getUserId(), MediaAction::TakedownRequested, $reason);
        $this->em->flush();
    }

    /**
     * A third party reports the photo (docs/specs/photo-uploads.md §6c). The
     * caller has already validated the inputs; what this decides is whether the
     * report CHANGES anything, and the answer is deliberately "almost never":
     *
     * - An ineligible photo (unknown handled by the caller, unapproved,
     *   tombstoned, already awaiting a decision) or an already-decided category
     *   is swallowed silently. The reporter sees the same acknowledgement
     *   either way — anything else turns the form into an existence oracle.
     * - An eligible report queues for a curator and the photo STAYS UP. The
     *   single exception is the intimate-imagery/child category, which
     *   withholds on the spot; its use is individually logged, because abusing
     *   the emergency lever is itself a moderation matter.
     * - That exception is itself bounded by a site-wide budget
     *   (UrgentWithholdBreaker). Once the budget is spent an urgent report
     *   files and pins like any other and takes nothing down — per-IP limits
     *   cannot bound a distributed attacker, and this route must not become a
     *   way to empty the map.
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
        // The budget is spent only by a report that would otherwise withhold —
        // an ordinary report must never move the breaker, or the cheap
        // categories could exhaust the expensive one's budget for free.
        $withheld = $urgent && $this->breaker->allowWithhold();

        $upload->reportThirdParty($category, $reason, $contact, $this->hashReporter($reporterIp), $withheld);
        if ($withheld) {
            $this->detach($upload);
            // Tell the contributor at once. Their photo has just vanished from
            // the map; without a word from us the obvious reading is that we
            // deleted their contribution. The message says hidden, says a
            // human is looking, and says nothing that identifies the reporter
            // or names the category that did it.
            $this->notify($upload, UserMessageKind::MediaHiddenPendingReview, 'messages.body.media_hidden_pending_review', null);
        }
        // Anonymous actor: null is honest — there may be no account behind
        // this report at all. The note carries the category and what the lever
        // did, so the log answers "who used auto-withhold" — and "what did we
        // do while the breaker was open" — without a join.
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

    /**
     * One hash per reporter IP, salted with the kernel secret: answers "is one
     * person reporting forty photos" without keeping raw IPs anywhere.
     */
    private function hashReporter(string $ip): string
    {
        return hash('sha256', $this->secret.'|'.$ip);
    }

    /**
     * Granted: the objects go, the row and its history stay.
     *
     * deleteObjects() rather than purge(). What is being erased is the image,
     * and the row that survives holds no image — it records that a photo
     * existed, that its subject asked for it, and that we did as asked. Erasing
     * the evidence of an erasure request would leave us unable to show we
     * honoured it, which Art. 5(2) accountability is precisely about.
     */
    public function grant(MediaUpload $upload, User $curator, ?string $note = null): void
    {
        // A held row is nobody's to decide but an admin's, and granting would
        // delete the objects the hold exists to preserve.
        if (!$upload->isTakedownPending() || $upload->isEscalated()) {
            return;
        }

        $thirdParty = MediaTakedownSource::ThirdParty === $upload->getTakedownSource();
        // A queued third-party report never detached — the photo stayed up by
        // design — so granting is the moment it leaves the gallery. Idempotent
        // for the paths that already withheld at request time.
        $this->detach($upload);
        $upload->resolveTakedown();
        $this->events->append($upload->getId(), (int) $curator->getId(), MediaAction::TakedownGranted, $note);
        $this->disposal->deleteObjects($upload);
        // The uploader is told either way, but not the same thing: "your
        // request is granted" and "your photo was removed after a report"
        // are different messages, and the second must not hint at who asked.
        $this->notify(
            $upload,
            $thirdParty ? UserMessageKind::MediaRemovedOnReport : UserMessageKind::MediaTakedownGranted,
            $thirdParty ? 'messages.body.media_removed_on_report' : 'messages.body.media_takedown_granted',
            $note,
        );
        $this->em->flush();
    }

    /**
     * Declined: the marker goes, the photo is published again (reattach is a
     * no-op when it never left), the reason stays on the row. A third-party
     * decline is FINAL for its category (docs/specs/photo-uploads.md §6c) and
     * tells the uploader nothing — nothing changed for them, and "somebody
     * reported you" is exactly the anxiety the single-response rule exists to
     * avoid spreading.
     */
    public function decline(MediaUpload $upload, User $curator, ?string $note = null): void
    {
        if (!$upload->isTakedownPending() || $upload->isEscalated()) {
            return;
        }

        $thirdParty = MediaTakedownSource::ThirdParty === $upload->getTakedownSource();
        // Read before the decision clears it: whether this request had hidden
        // the photo decides whether the uploader is owed the other half of a
        // message we already sent them.
        $wasHidden = $upload->isTakedownWithheld();
        $upload->declineTakedown();
        $this->reattach($upload);
        $this->events->append($upload->getId(), (int) $curator->getId(), MediaAction::TakedownDeclined, $note);
        if (!$thirdParty) {
            $this->notify($upload, UserMessageKind::MediaTakedownDeclined, 'messages.body.media_takedown_declined', $note);
        } elseif ($wasHidden) {
            // We told them it was hidden, so we tell them it is back. A report
            // that only queued stays silent: nothing they could see ever
            // changed, and "somebody accused you" is not ours to volunteer.
            $this->notify($upload, UserMessageKind::MediaRestoredAfterReview, 'messages.body.media_restored_after_review', null);
        }
        $this->em->flush();
    }

    /**
     * Undo an abusive auto-withhold (docs/specs/photo-uploads.md §6c). The
     * recovery tool for the case the circuit breaker bounds but cannot
     * prevent: a flood of urgent reports that each took a photo down before a
     * human saw it.
     *
     * Why this is not `decline()`, which would be the obvious reuse. A decline
     * is a *judgement on a claim*: it closes that category for that photo
     * forever (the finality ledger), so mass-declining a flood would quietly
     * immunise every attacked photo against the next genuine report of the
     * same kind — turning a day's vandalism into a permanent hole. Dismissal
     * says only "this report was not real": the photo goes back, the ledger is
     * untouched, and a future genuine claim is still heard.
     *
     * The uploader is not messaged either. Nothing about their photo changed
     * that they ever saw, and "somebody accused you and we decided they were
     * lying" is a worse thing to receive than silence.
     *
     * @return bool whether anything was restored — false for a row somebody else already decided
     */
    public function dismissAsAbuse(MediaUpload $upload, User $admin, ?string $note = null): bool
    {
        if (!$upload->isTakedownPending()
            || MediaTakedownSource::ThirdParty !== $upload->getTakedownSource()
            // A bulk restore must never put a held photo back on the map.
            || $upload->isEscalated()) {
            return false;
        }

        $wasHidden = $upload->isTakedownWithheld();
        $upload->dismissTakedownAsAbuse();
        $this->reattach($upload);
        $this->events->append($upload->getId(), (int) $admin->getId(), MediaAction::TakedownDismissedAsAbuse, $note);
        if ($wasHidden) {
            // Same promise as a decline: if we told them it was hidden, we
            // tell them it is back. The operator's note stays internal — it
            // usually says "co-ordinated flood", which is our business.
            $this->notify($upload, UserMessageKind::MediaRestoredAfterReview, 'messages.body.media_restored_after_review', null);
        }
        $this->em->flush();

        return true;
    }

    /**
     * The recovery desk's worklist: third-party requests that took a photo
     * down and are still waiting. Newest first — an attack arrives in a burst,
     * and the burst is what an operator is here to undo.
     *
     * @return list<array{uuid: string, sm: string, reason: string, requestedAt: \DateTimeImmutable, itemName: string, category: ?string, reporter: string}>
     */
    public function withheldThirdPartyCards(): array
    {
        /** @var list<MediaUpload> $rows */
        $rows = $this->em->createQuery(
            'SELECT m FROM '.MediaUpload::class.' m
             WHERE m.takedownRequestedAt IS NOT NULL AND m.objectsDeletedAt IS NULL
               AND m.takedownSource = :source AND m.takedownWithheld = true
               AND m.escalatedAt IS NULL
             ORDER BY m.takedownRequestedAt DESC',
        )->setParameter('source', MediaTakedownSource::ThirdParty)->getResult();

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
                // Eight characters of the salted hash: enough for an operator
                // to see "these forty all came from one place" at a glance,
                // and no more identifying than the full hash already is.
                'reporter' => substr($upload->getTakedownReporterHash() ?? '', 0, 8),
            ];
        }

        return $cards;
    }

    /**
     * Art. 5(1)(e): the reply address has no purpose once the month to answer
     * (Art. 12(3)) is long past. Swept by media:gc alongside the other two
     * retention windows (docs/specs/photo-uploads.md §6c).
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

    /** @return list<MediaUpload> oldest first — a rights request waits for nobody's convenience */
    public function pending(): array
    {
        /** @var list<MediaUpload> $rows */
        $rows = $this->em->createQuery(
            // escalatedAt IS NULL: a photo under legal hold leaves the
            // curator desk entirely (photo-uploads.md §6d). Whatever was
            // pending on it is the admin's problem now, and no curator should
            // be shown the thumbnail again.
            'SELECT m FROM '.MediaUpload::class.' m
             WHERE m.takedownRequestedAt IS NOT NULL AND m.objectsDeletedAt IS NULL
               AND m.escalatedAt IS NULL
             ORDER BY m.takedownRequestedAt ASC',
        )->getResult();

        return $rows;
    }

    /**
     * The desk's view of pending() — deliberately not region-scoped, unlike
     * the submission queue. A rights request is not editorial work to be
     * shared out by jurisdiction; whoever is on duty should see it.
     *
     * @return list<array{uuid: string, sm: string, reason: string, requestedAt: \DateTimeImmutable, itemName: string, source: string, category: ?string, contact: ?string, withheld: bool}>
     */
    public function pendingCards(): array
    {
        $cards = [];
        foreach ($this->pending() as $upload) {
            $requestedAt = $upload->getTakedownRequestedAt();
            if (null === $requestedAt) {
                continue;   // unreachable via pending(), and cheaper than a nullable in the view
            }
            $cards[] = [
                'uuid' => $upload->getId()->toRfc4122(),
                'sm' => (string) $this->decisions->describe($upload)['sm'],
                'reason' => $upload->getTakedownReason() ?? '',
                'requestedAt' => $requestedAt,
                'itemName' => $this->item($upload)?->getName() ?? '',
                'source' => $upload->getTakedownSource() ?? MediaTakedownSource::Uploader,
                'category' => $upload->getTakedownCategory(),
                // The reply address is shown to the curator ONLY — they are
                // the controller answering the request. It never appears in
                // any message to the uploader.
                'contact' => $upload->getTakedownContact(),
                'withheld' => $upload->isTakedownWithheld(),
            ];
        }

        return $cards;
    }

    /** Drops the photo out of the item's gallery. Matched on the sm URL, which carries the uuid. */
    private function detach(MediaUpload $upload): void
    {
        $item = $this->item($upload);
        if (null === $item) {
            return;
        }

        $target = $this->decisions->describe($upload)['sm'];
        $attributes = $item->getAttributes();
        $photos = $attributes['photos'] ?? null;
        if (!\is_array($photos)) {
            return;
        }

        $kept = array_values(array_filter(
            $photos,
            static fn (mixed $photo): bool => !\is_array($photo) || ($photo['sm'] ?? null) !== $target,
        ));

        // An empty gallery drops the key rather than storing []: map.js reads
        // `f.photos || [f.photo]`, and an empty array is truthy, so leaving one
        // behind would shadow a legacy singular that may still be there.
        if ([] === $kept) {
            unset($attributes['photos']);
        } else {
            $attributes['photos'] = $kept;
        }
        $item->setAttributes($attributes);
    }

    /** Puts it back, in the shape approval originally gave it. */
    private function reattach(MediaUpload $upload): void
    {
        $item = $this->item($upload);
        if (null === $item) {
            return;
        }

        $entry = $this->decisions->describe($upload);
        $attributes = $item->getAttributes();
        $photos = $attributes['photos'] ?? null;
        $gallery = \is_array($photos) ? array_values(array_filter($photos, is_array(...))) : [];

        foreach ($gallery as $photo) {
            if (($photo['sm'] ?? null) === $entry['sm']) {
                return;   // already there — decline is idempotent
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

    /**
     * Tells the rider what happened. Silently does nothing for a photo whose
     * account is already gone: media_upload.user_id has no foreign key and is
     * nulled by anonymization, and there is nobody left to write to.
     */
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
