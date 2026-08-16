<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media;

use App\Catalog\Entity\Submission;
use App\Entity\User;
use App\Media\Entity\MediaUpload;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Binds a rider's uploads to the submission that carries them, and performs the
 * one and only use of the harvested coordinates
 * (docs/specs/photo-uploads.md §3): the distance from the photo to the
 * submission pin is computed, and the raw coordinates are destroyed in the same
 * transaction.
 *
 * Every id is re-validated here regardless of what the browser believed: it
 * must exist, still be pending, still be unclaimed, and belong to the
 * submitting rider. The wizard's six-photo cap is likewise re-checked — a
 * client-side limit is a courtesy, never a control.
 *
 * @api Called by CatalogContributionService::submitDraft() inside its transaction.
 */
final class MediaClaimService
{
    public const int MAX_PER_SUBMISSION = 6;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaEventLog $events,
    ) {
    }

    public function claim(mixed $rawMediaIds, User $by, Submission $submission): void
    {
        $ids = self::parse($rawMediaIds);
        if ([] === $ids) {
            return;
        }
        if (\count($ids) > self::MAX_PER_SUBMISSION) {
            throw new \InvalidArgumentException(\sprintf('A submission carries at most %d photos, %d given.', self::MAX_PER_SUBMISSION, \count($ids)));
        }

        $submissionId = (int) $submission->getId();
        $pinLat = null;
        $pinLng = null;
        $geom = json_decode((string) $submission->getGeom(), true);
        if (\is_array($geom) && isset($geom['coordinates'][0], $geom['coordinates'][1])) {
            $pinLng = (float) $geom['coordinates'][0];
            $pinLat = (float) $geom['coordinates'][1];
        }

        foreach ($ids as $id) {
            $upload = $this->em->find(MediaUpload::class, $id);
            if (null === $upload) {
                throw new \InvalidArgumentException(\sprintf('Unknown upload %s.', $id->toRfc4122()));
            }
            // PendingScan counts as claimable, and has to: the wizard stops
            // WAITING for a slow scan after 30 seconds, it does not throw the
            // photo away (docs/specs/photo-uploads.md §4). Refusing here would
            // turn a scanner that took half a minute into a lost contribution
            // and a failed submit.
            if (!\in_array($upload->getStatus(), [MediaStatus::Pending, MediaStatus::PendingScan], true)) {
                throw new \InvalidArgumentException(\sprintf('Upload %s is already decided.', $id->toRfc4122()));
            }
            if (null !== $upload->getSubmissionId()) {
                throw new \InvalidArgumentException(\sprintf('Upload %s already belongs to a submission.', $id->toRfc4122()));
            }
            if ($upload->getUserId() !== (int) $by->getId()) {
                throw new \InvalidArgumentException(\sprintf('Upload %s belongs to another rider.', $id->toRfc4122()));
            }

            $upload->claim($submissionId);
            $upload->resolveGps(GpsDistance::metres($upload->getGpsLat(), $upload->getGpsLng(), $pinLat, $pinLng));
            $this->events->append($upload->getId(), (int) $by->getId(), MediaAction::Claimed);
        }
    }

    /** @return list<Uuid> */
    private static function parse(mixed $raw): array
    {
        if (\is_array($raw)) {
            $values = $raw;
        } elseif (\is_string($raw) && '' !== trim($raw)) {
            $decoded = json_decode($raw, true);
            if (!\is_array($decoded)) {
                throw new \InvalidArgumentException('The photo list is not a JSON array.');
            }
            $values = $decoded;
        } else {
            return [];
        }

        $ids = [];
        foreach ($values as $value) {
            if (!\is_string($value) || !Uuid::isValid($value)) {
                throw new \InvalidArgumentException('The photo list contains something that is not an upload id.');
            }
            $ids[] = Uuid::fromString($value);
        }

        // A duplicated id would otherwise claim, then fail on itself.
        return array_values(array_unique($ids, \SORT_REGULAR));
    }
}
