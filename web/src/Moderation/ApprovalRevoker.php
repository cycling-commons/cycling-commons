<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Moderation;

use App\Catalog\ClimbFoot;
use App\Catalog\Entity\ChangeHistory;
use App\Catalog\Entity\Item;
use App\Catalog\Entity\Submission;
use App\Catalog\Import\OsmCandidates;
use App\Catalog\ItemState;
use App\Catalog\ItemType;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use App\Entity\User;
use App\Media\Entity\MediaUpload;
use App\Media\MediaAction;
use App\Media\MediaDecisionService;
use App\Media\MediaEventLog;
use App\Media\MediaStatus;
use App\Media\PhotoAltSuggestion;
use App\Messaging\MessageService;
use App\Messaging\UserMessageKind;
use App\Service\AdminActionLogger;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Takes back an approval (owner 2026-10-20): what the approval changed is
 * undone, and the submission waits in the queue again for a decision like
 * any other. A curator of the submission's area does it, with a reason the
 * rider is told.
 *
 * What an approval changed is what it recorded: the `change_history` rows
 * carrying its submission id (a place's fields, a new place's state, the
 * places it retired, its gallery), its photos, and for a town or region
 * text the entry it wrote (PlaceTextWriter). Each row is put back to its
 * `old_value`, and the undo is recorded as rows of its own, credited to the
 * curator and carrying the same submission. `payload._revoked[].historyUpTo`
 * closes that approval: its rows and its undo rows are at or below it, so a
 * second approval's rows are told apart, and a later check does not count a
 * revoked approval as a change made since.
 *
 * Nothing newer is ever overwritten: when any value the approval wrote was
 * changed afterwards (a later history row on the same place and field, a
 * place no longer in the state the approval left it, a text written over),
 * the revoke is refused and names those values. The curator corrects them
 * by hand.
 *
 * @see docs/specs/moderation-and-contribution.md §5.6
 *
 * @api
 */
final readonly class ApprovalRevoker
{
    /** Admin log action; the note is content-free, as Trash's is. */
    public const string ACTION = 'submission_revoked';

    /** The reason's cap: the map's decision form's. */
    public const int REASON_MAX = 2000;

    public function __construct(
        private EntityManagerInterface $em,
        private Connection $db,
        private ModerationScopeProvider $scopes,
        private PlaceTextWriter $placeTexts,
        private MediaDecisionService $mediaDecisions,
        private MediaEventLog $mediaEvents,
        private OsmCandidates $osmCandidates,
        private MessageService $messages,
        private AdminActionLogger $adminLog,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OutOfScopeException outside the curator's areas
     * @throws RevokeRefused       not approved, under hold, no reason, or changed since
     */
    public function revoke(int $submissionId, User $curator, string $reason): Submission
    {
        $reason = trim($reason);
        if ('' === $reason) {
            throw new RevokeRefused('moderate.revoke.error.reason_required');
        }
        if (mb_strlen($reason) > self::REASON_MAX) {
            throw new RevokeRefused('moderate.error.note_too_long');
        }

        // Every refusal is decided before the transaction, so a refused
        // revoke leaves the request's entity manager open; the same checks
        // run again under the lock, against a revoke or approval racing it.
        $this->assertRevocable($this->em->find(Submission::class, $submissionId), $curator);

        return $this->em->wrapInTransaction(function () use ($submissionId, $curator, $reason): Submission {
            $submission = $this->em->find(Submission::class, $submissionId, LockMode::PESSIMISTIC_WRITE);
            $rows = $this->assertRevocable($submission, $curator);
            $payload = $submission->getPayload();
            if (SubmissionType::Text === $submission->getType()) {
                $this->placeTexts->revert($submission);
            }

            $now = $this->clock->now();
            $touched = [];
            foreach (array_reverse($rows) as $row) {
                $item = $this->em->find(Item::class, $row['item_id']);
                if (null === $item) {
                    continue;
                }
                $this->undo($item, $row, $curator, (int) $submission->getId());
                $touched[(int) $item->getId()] = $item;
            }
            foreach ($touched as $item) {
                // A climb's point is the foot of its line, after the line went back as after it changed.
                $foot = ItemType::Climbs->letter() === $item->getLetter() ? ClimbFoot::of($item->getAttributes()['route'] ?? null) : null;
                if (null !== $foot) {
                    $item->setGeom(json_encode(['type' => 'Point', 'coordinates' => [$foot[1], $foot[0]]], \JSON_THROW_ON_ERROR));
                }
            }
            $this->reopenPhotos($submission, $curator, $reason);
            $this->dropOwnAnswer($submission);

            // The record of this approval and its revoke, on the submission,
            // for the queue card and for the next revoke: history rows up to
            // here belong to this approval.
            $this->em->flush();
            $revoked = \is_array($payload['_revoked'] ?? null) ? array_values($payload['_revoked']) : [];
            $revoked[] = [
                'by' => (int) $curator->getId(),
                'at' => $now->format(\DateTimeInterface::ATOM),
                'reason' => $reason,
                'approvedBy' => $submission->getDecidedBy(),
                'approvedAt' => $submission->getDecidedAt()?->format(\DateTimeInterface::ATOM),
                'historyUpTo' => (int) $this->db->fetchOne('SELECT COALESCE(MAX(id), 0) FROM change_history'),
            ];
            $payload['_revoked'] = $revoked;
            // A text's Wikipedia credit is decided again with the approval:
            // the writer's own answer stands until then.
            if (\is_array($payload['_credit'] ?? null)) {
                $claim = $payload['_credit']['claim'] ?? null;
                $payload['derived'] = true === $claim;
                unset($payload['_credit']);
            }
            unset($payload['_before']);
            // The approval's tick on the links goes with it; the writer's stays.
            if (\is_array($payload['_links'] ?? null)) {
                unset($payload['_links']['approved']);
                if ([] === $payload['_links']) {
                    unset($payload['_links']);
                }
            }
            $submission->setPayload($payload)
                ->setStatus(SubmissionStatus::Pending)
                ->setDecisionNote(null)
                ->setDecidedBy(null)
                ->setDecidedAt(null);

            $riderId = $submission->getUserId();
            if (null !== $riderId && $riderId !== $curator->getId()) {
                $this->messages->sendSystem(
                    $riderId, UserMessageKind::SubmissionRevoked,
                    'submission', $submissionId, 'SUB-'.$submissionId,
                    'messages.body.'.UserMessageKind::SubmissionRevoked->value, ['%title%' => $submission->getTitle()],
                    $reason,
                );
            }
            $this->adminLog->log($curator, self::ACTION, null, sprintf('SUB-%d · type=%s', $submissionId, $submission->getType()->value));

            return $submission;
        });
    }

    /**
     * Whether this curator may revoke this approval now: inside their areas,
     * approved, not held, and nothing it wrote changed since.
     *
     * @return list<array{id: int, item_id: int, field: string, old: mixed, new: mixed}> the approval's history rows
     *
     * @throws OutOfScopeException
     * @throws RevokeRefused
     *
     * @phpstan-assert Submission $submission
     */
    private function assertRevocable(?Submission $submission, User $curator): array
    {
        if (null === $submission || $submission->isTrashed()) {
            throw new RevokeRefused('moderate.revoke.error.not_approved');
        }
        if (!$this->scopes->allowsRegion($this->scopes->scopeFor($curator), $submission->getRegionId())) {
            throw new OutOfScopeException('Submission outside the curator\'s assigned areas.');
        }
        if (SubmissionStatus::Approved !== $submission->getStatus()) {
            throw new RevokeRefused('moderate.revoke.error.not_approved');
        }
        if ($submission->isEscalated()) {
            throw new RevokeRefused('moderate.revoke.error.held');
        }
        $rows = $this->approvalRows($submission);
        $this->assertUnchangedSince($rows, (int) $submission->getId());
        if (SubmissionType::Text === $submission->getType()) {
            $this->placeTexts->assertRevertable($submission);
        }

        return $rows;
    }

    /**
     * The history rows of the submission's latest approval: its own rows
     * since the last revoke, oldest first.
     *
     * @return list<array{id: int, item_id: int, field: string, old: mixed, new: mixed}>
     */
    private function approvalRows(Submission $submission): array
    {
        $revoked = $submission->getPayload()['_revoked'] ?? null;
        $last = \is_array($revoked) && [] !== $revoked ? end($revoked) : null;
        $after = \is_array($last) && \is_int($last['historyUpTo'] ?? null) ? $last['historyUpTo'] : 0;

        $rows = $this->db->fetchAllAssociative(
            'SELECT id, item_id, field, old_value, new_value FROM change_history
              WHERE submission_id = :s AND id > :after ORDER BY id',
            ['s' => (int) $submission->getId(), 'after' => $after],
        );

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'item_id' => (int) $r['item_id'],
            'field' => (string) $r['field'],
            'old' => null === $r['old_value'] ? null : json_decode((string) $r['old_value'], true),
            'new' => null === $r['new_value'] ? null : json_decode((string) $r['new_value'], true),
        ], $rows);
    }

    /**
     * Refuses when anything the approval wrote changed afterwards: a later
     * history row on the same place and field, a place that is gone, or a
     * value that no longer reads as the approval left it (a curator's desk
     * write that keeps no history, such as the duplicates desk's retire).
     *
     * A later approval that was itself revoked is no change: its rows and
     * its undo rows lie at or below its own `historyUpTo`, and the value is
     * back.
     *
     * @param list<array{id: int, item_id: int, field: string, old: mixed, new: mixed}> $rows
     *
     * @throws RevokeRefused naming the changed values
     */
    private function assertUnchangedSince(array $rows, int $submissionId): void
    {
        $changed = [];
        foreach ($rows as $row) {
            $later = $this->db->fetchOne(
                "SELECT 1 FROM change_history h
                  WHERE h.item_id = :i AND h.field = :f AND h.id > :id
                    AND h.submission_id IS DISTINCT FROM :s
                    AND NOT (h.submission_id IS NOT NULL AND h.id <= COALESCE((
                        SELECT MAX((r->>'historyUpTo')::bigint) FROM submission o, jsonb_array_elements(o.payload->'_revoked') r
                         WHERE o.id = h.submission_id AND jsonb_typeof(o.payload->'_revoked') = 'array'), 0))
                  LIMIT 1",
                ['i' => $row['item_id'], 'f' => $row['field'], 'id' => $row['id'], 's' => $submissionId],
            );
            $item = $this->em->find(Item::class, $row['item_id']);
            if (false !== $later || null === $item || !self::stillReads($item, $row['field'], $row['new'])) {
                $changed[] = null === $item ? 'place #'.$row['item_id'] : $item->getName().': '.$row['field'];
            }
        }
        if ([] !== $changed) {
            throw new RevokeRefused('moderate.revoke.error.changed_since', array_values(array_unique($changed)));
        }
    }

    /** Whether a field still holds what the approval wrote, where the place can tell. */
    private static function stillReads(Item $item, string $field, mixed $new): bool
    {
        return match (true) {
            'state' === $field => $item->getState()->value === $new,
            Item::NAME_FIELD === $field => $item->getName() === $new,
            'osm_ref' === $field, 'osmRef' === $field => $item->getOsmRef() === $new,
            Item::LOCATION_FIELD === $field, 'replaced_by' === $field, null !== PhotoAltSuggestion::photoOf($field) => true,
            default => ($item->getAttributes()[$field] ?? null) == $new,
        };
    }

    /**
     * One history row put back to its old value, and the undo recorded,
     * credited to the curator, under the submission it undoes.
     *
     * @param array{id: int, item_id: int, field: string, old: mixed, new: mixed} $row
     */
    private function undo(Item $item, array $row, User $curator, int $submissionId): void
    {
        $field = $row['field'];
        $old = $row['old'];
        $attributes = $item->getAttributes();
        switch (true) {
            case 'replaced_by' === $field:
                // A note on the retired place, not a value it holds.
                return;
            case 'state' === $field:
                $state = \is_string($old) ? ItemState::tryFrom($old) : null;
                if (null === $state) {
                    return;
                }
                $item->setState($state);
                break;
            case Item::NAME_FIELD === $field:
                $item->setName((string) $old);
                break;
            case 'osm_ref' === $field || 'osmRef' === $field:
                $item->setOsmRef(\is_string($old) ? $old : null);
                break;
            case Item::LOCATION_FIELD === $field:
                $parts = array_map('trim', explode(',', (string) $old));
                if (2 !== \count($parts) || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
                    return;
                }
                $item->setGeom(json_encode(['type' => 'Point', 'coordinates' => [(float) $parts[1], (float) $parts[0]]], \JSON_THROW_ON_ERROR));
                $this->osmCandidates->refreshAt((int) $item->getId(), $item->getLetter(), (float) $parts[0], (float) $parts[1]);
                break;
            case null !== PhotoAltSuggestion::photoOf($field):
                $this->undoPhotoAlt($item, PhotoAltSuggestion::photoOf($field), \is_string($old) ? $old : null);
                break;
            default:
                if (null === $old) {
                    unset($attributes[$field]);
                } else {
                    $attributes[$field] = $old;
                    if ('segment' === $field && \is_array($old) && isset($old['a'], $old['b'])) {
                        $path = \is_array($old['line'] ?? null) ? $old['line'] : [$old['a'], $old['b']];
                        $item->setGeom(json_encode(['type' => 'LineString', 'coordinates' => $path], \JSON_THROW_ON_ERROR));
                    }
                }
                $item->setAttributes($attributes);
        }

        $this->em->persist((new ChangeHistory())
            ->setItemId((int) $item->getId())
            ->setSubmissionId($submissionId)
            ->setField($field)
            ->setOldValue($row['new'])
            ->setNewValue($old)
            ->setChangedBy((int) $curator->getId()));
    }

    /** A photo's description back to what it was, on the upload and in the gallery, as ModerationService writes it. */
    private function undoPhotoAlt(Item $item, Uuid $photo, ?string $alt): void
    {
        $upload = $this->em->find(MediaUpload::class, $photo);
        if (!$upload instanceof MediaUpload || $upload->getItemId() !== $item->getId()) {
            return;
        }
        $upload->setAltText($alt);
        $attributes = $item->getAttributes();
        $photos = $attributes['photos'] ?? null;
        if (!\is_array($photos)) {
            return;
        }
        foreach ($photos as $index => $entry) {
            if (!$this->mediaDecisions->isEntryFor($entry, $upload)) {
                continue;
            }
            if (null === $alt || '' === $alt) {
                unset($photos[$index]['alt']);
            } else {
                $photos[$index]['alt'] = $alt;
            }
        }
        $attributes['photos'] = array_values($photos);
        $item->setAttributes($attributes);
    }

    /**
     * The photos the approval decided wait for a decision again. Their
     * gallery entries went with the `photos` history row; their bytes are
     * public while they wait, as they were before. A photo whose objects are
     * gone (a rejected one past its retention) stays as it is.
     */
    private function reopenPhotos(Submission $submission, User $curator, string $reason): void
    {
        $uploads = $this->em->getRepository(MediaUpload::class)->findBy(['submissionId' => $submission->getId()]);
        foreach ($uploads as $upload) {
            if (!\in_array($upload->getStatus(), [MediaStatus::Approved, MediaStatus::Rejected], true) || !$upload->hasPublishedObjects() || $upload->isEscalated()) {
                continue;
            }
            $upload->reopen();
            $this->mediaEvents->append($upload->getId(), (int) $curator->getId(), MediaAction::Reopened, $reason);
        }
    }

    /**
     * A new water place's own potability answer, recorded from the form at
     * approval (ModerationService::carryOwnAnswer()), goes with it.
     */
    private function dropOwnAnswer(Submission $submission): void
    {
        if (SubmissionType::NewItem !== $submission->getType() || null === $submission->getItemId() || null === $submission->getUserId()) {
            return;
        }
        $this->db->executeStatement(
            "DELETE FROM item_confirmation WHERE item_id = :i AND user_id = :u AND source = 'form'",
            ['i' => $submission->getItemId(), 'u' => $submission->getUserId()],
        );
    }
}
