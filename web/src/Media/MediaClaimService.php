<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media;

use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\Entity\RouteSuggestion;
use App\Catalog\Entity\Submission;
use App\Entity\User;
use App\Media\Entity\MediaUpload;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Bind uploads to a submission or a route; compute pin distance and destroy GPS.
 *
 * @see docs/specs/photo-uploads.md §3, §5i
 *
 * @api
 */
final class MediaClaimService
{
    public const int MAX_PER_SUBMISSION = 6;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaEventLog $events,
    ) {
    }

    public function claim(mixed $rawMediaIds, User $by, Submission $submission, mixed $rawAlts = null): void
    {
        $submissionId = (int) $submission->getId();
        $pinLat = null;
        $pinLng = null;
        $geom = json_decode((string) $submission->getGeom(), true);
        if (\is_array($geom) && isset($geom['coordinates'][0], $geom['coordinates'][1])) {
            $pinLng = (float) $geom['coordinates'][0];
            $pinLat = (float) $geom['coordinates'][1];
        }

        $this->bind($rawMediaIds, $by, $rawAlts, static function (MediaUpload $upload) use ($submissionId, $pinLat, $pinLng): array {
            $upload->claim($submissionId);

            return [$pinLat, $pinLng];
        });
    }

    /**
     * Bind uploads to a recommended route: its proposal (`$suggestion` null)
     * or a photo correction on it. Same checks, same cap, same GPS rule as a
     * submission, except that the distance is measured to the nearest point
     * of the route's line, and that point is the pin it was measured to.
     *
     * @see docs/specs/photo-uploads.md §5i
     */
    public function claimForRoute(mixed $rawMediaIds, User $by, RecommendedRoute $route, ?RouteSuggestion $suggestion, mixed $rawAlts = null): void
    {
        $routeId = (int) $route->getId();
        $suggestionId = $suggestion?->getId();
        $line = self::lineOf($route);

        $this->bind($rawMediaIds, $by, $rawAlts, static function (MediaUpload $upload) use ($routeId, $suggestionId, $line): array {
            $upload->claimForRoute($routeId, $suggestionId);
            $lat = $upload->getGpsLat();
            $lng = $upload->getGpsLng();
            $pin = null === $lat || null === $lng ? null : GpsDistance::nearestOnLine($lat, $lng, $line);

            return null === $pin ? [null, null] : $pin;
        });
    }

    /**
     * GeoJSON coordinates of a route's stored line, or none.
     *
     * @return list<mixed>
     *
     * @api
     */
    public static function lineOf(RecommendedRoute $route): array
    {
        $geom = json_decode((string) $route->getGeom(), true);
        $coords = \is_array($geom) ? ($geom['coordinates'] ?? null) : null;

        return \is_array($coords) ? array_values($coords) : [];
    }

    /**
     * @param callable(MediaUpload): array{0: ?float, 1: ?float} $attach binds one upload and answers the pin to measure it to
     */
    private function bind(mixed $rawMediaIds, User $by, mixed $rawAlts, callable $attach): void
    {
        $ids = self::parse($rawMediaIds);
        if ([] === $ids) {
            return;
        }
        $alts = self::parseAlts($rawAlts);
        if (\count($ids) > self::MAX_PER_SUBMISSION) {
            throw new \InvalidArgumentException(\sprintf('A submission carries at most %d photos, %d given.', self::MAX_PER_SUBMISSION, \count($ids)));
        }

        foreach ($ids as $id) {
            $upload = $this->em->find(MediaUpload::class, $id);
            if (null === $upload) {
                throw new \InvalidArgumentException(\sprintf('Unknown upload %s.', $id->toRfc4122()));
            }
            // PendingScan is claimable: wizard patience must not drop the photo. @see docs/specs/photo-uploads.md §4
            if (!\in_array($upload->getStatus(), [MediaStatus::Pending, MediaStatus::PendingScan], true)) {
                throw new \InvalidArgumentException(\sprintf('Upload %s is already decided.', $id->toRfc4122()));
            }
            if ($upload->isClaimed()) {
                throw new \InvalidArgumentException(\sprintf('Upload %s already belongs to a submission.', $id->toRfc4122()));
            }
            if ($upload->getUserId() !== (int) $by->getId()) {
                throw new \InvalidArgumentException(\sprintf('Upload %s belongs to another rider.', $id->toRfc4122()));
            }

            [$pinLat, $pinLng] = $attach($upload);
            // The description typed in the wizard normally lands through its
            // own request; when that request lost to the page moving on, the
            // copy in the submission is the one that survives. Never over a
            // description already there: the live save is the fresher word.
            $typed = $alts[$id->toRfc4122()] ?? null;
            if (null !== $typed && '' === trim((string) $upload->getAltText())) {
                $upload->setAltText($typed);
            }
            $upload->resolveGps(GpsDistance::between($upload->getGpsLat(), $upload->getGpsLng(), $pinLat, $pinLng), $pinLat, $pinLng);
            $this->events->append($upload->getId(), (int) $by->getId(), MediaAction::Claimed);
        }
    }

    /**
     * `{"<uuid>": "<description>"}` from the wizard's hidden field, lower-cased
     * keys, trimmed values, the same 300-character cap the live route applies.
     * Anything malformed is simply no descriptions: a photo must never be
     * refused because of the words beside it.
     *
     * @return array<string, string>
     */
    private static function parseAlts(mixed $raw): array
    {
        if (!\is_string($raw) || '' === trim($raw)) {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!\is_array($decoded)) {
            return [];
        }
        $out = [];
        /** @var mixed $text */
        foreach ($decoded as $key => $text) {
            if (!\is_string($key) || !\is_string($text) || !Uuid::isValid($key)) {
                continue;
            }
            $text = trim($text);
            if ('' === $text || mb_strlen($text) > 300) {
                continue;
            }
            $out[strtolower($key)] = $text;
        }

        return $out;
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
