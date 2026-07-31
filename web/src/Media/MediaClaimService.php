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
    private const int EARTH_RADIUS_M = 6_371_000;

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
            if (MediaStatus::Pending !== $upload->getStatus()) {
                throw new \InvalidArgumentException(\sprintf('Upload %s is already decided.', $id->toRfc4122()));
            }
            if (null !== $upload->getSubmissionId()) {
                throw new \InvalidArgumentException(\sprintf('Upload %s already belongs to a submission.', $id->toRfc4122()));
            }
            if ($upload->getUserId() !== (int) $by->getId()) {
                throw new \InvalidArgumentException(\sprintf('Upload %s belongs to another rider.', $id->toRfc4122()));
            }

            $upload->claim($submissionId);
            $upload->resolveGps(self::distanceM($upload->getGpsLat(), $upload->getGpsLng(), $pinLat, $pinLng));
            $this->events->append($upload->getId(), (int) $by->getId(), MediaAction::Claimed);
        }
    }

    /**
     * How far the shot was taken from the pin, in whole metres — the only thing
     * that survives of the photo's coordinates. Null whenever either end is
     * missing: an absent distance is honest, a zero would be a claim.
     */
    private static function distanceM(?float $photoLat, ?float $photoLng, ?float $pinLat, ?float $pinLng): ?int
    {
        if (null === $photoLat || null === $photoLng || null === $pinLat || null === $pinLng) {
            return null;
        }

        $halfLat = \sin(deg2rad($pinLat - $photoLat) / 2.0);
        $halfLng = \sin(deg2rad($pinLng - $photoLng) / 2.0);
        $a = $halfLat * $halfLat
            + \cos(deg2rad($photoLat)) * \cos(deg2rad($pinLat)) * $halfLng * $halfLng;

        return (int) round(2.0 * (float) self::EARTH_RADIUS_M * \asin(min(1.0, \sqrt($a))));
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
