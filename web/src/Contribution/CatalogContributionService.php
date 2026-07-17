<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Contribution;

use App\Catalog\Entity\Item;
use App\Catalog\Entity\Submission;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\ItemType;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use App\Entity\User;
use App\Service\ContributionReceipt;
use App\Service\ContributionStubInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Turns contribute-form payloads into catalog submissions for moderator
 * review. New climbs create an item plus a submission. Improvements
 * snapshot only the fields that changed.
 *
 * @see docs/specs/moderation-and-contribution.md
 *
 * @api Autowired via ContributionStubInterface.
 */
final class CatalogContributionService implements ContributionStubInterface
{
    /**
     * Maps AddClimbType field names to registry attribute keys (letter B
     * vocabulary). Length, elevation gain and "already in OSM?" have no
     * matching attribute: they are derived from geometry or are
     * submission-only metadata. Both stay out of `attributes` but are kept
     * verbatim in the submission's raw `payload` for moderator review.
     *
     * @see docs/specs/edit-items/B-climbs.md
     */
    private const array CLIMB_FIELDS = [
        'fAvg' => 'avgGradient',
        'fMax' => 'maxGradient',
        'fSurface' => 'surface',
        'fSurfaceQ' => 'sq',
        'fTraffic' => 'tr',
        'fNote' => 'correction',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validator,
        private readonly SpatialResolver $resolver,
        private readonly RateLimiterFactoryInterface $contributionSubmitLimiter,
    ) {
    }

    #[\Override]
    public function submit(string $kind, array $payload, ?User $by): ContributionReceipt
    {
        if (null === $by) {
            throw new \LogicException('Contributions require an authenticated user.');
        }

        return match ($kind) {
            'climb' => $this->submitClimb($payload, $by),
            'improve' => $this->submitImprove($payload, $by),
            // 'vote' (and any other kind) is intentionally not persisted here.
            // Voting is verification-gate machinery, not catalog intake.
            default => new ContributionReceipt(
                'CC-'.strtoupper(bin2hex(random_bytes(6))), $kind, false, new \DateTimeImmutable(),
            ),
        };
    }

    /** @param array<string, mixed> $payload */
    private function submitClimb(array $payload, User $by): ContributionReceipt
    {
        // Reject rather than coerce: an absent, blank, or non-numeric
        // coordinate must not silently become 0.0. The geocoder always fills
        // these fields; a violation here surfaces as a normal form error.
        if (!is_numeric($payload['lat'] ?? null) || !is_numeric($payload['lng'] ?? null)) {
            $this->reject('contribute.error.invalid_location', 'lat');
        }

        $attributes = [];
        foreach (self::CLIMB_FIELDS as $formKey => $attrKey) {
            $v = $payload[$formKey] ?? null;
            if (null !== $v && '' !== $v) {
                $attributes[$attrKey] = \is_float($v) || \is_int($v) ? $v : (string) $v;
            }
        }

        // Surface malformed editor output as a form error instead of silently
        // discarding the drawn shape (every other field error surfaces too).
        try {
            $attributes += ClimbGeometry::fromPayload($payload);
        } catch (\InvalidArgumentException) {
            $this->reject('contribute.error.invalid_geometry', 'route');
        }

        $draft = new SubmissionDraft(
            type: ItemType::Climbs,
            title: (string) ($payload['fName'] ?? ''),
            // Guaranteed numeric by the guard above.
            lat: (float) $payload['lat'],
            lng: (float) $payload['lng'],
            attributes: $attributes,
        );

        $submission = $this->submitDraft($draft, SubmissionType::NewItem, $by, $payload);

        return new ContributionReceipt(
            'SUB-'.(string) $submission->getId(), 'climb', true, $submission->getCreatedAt(), $submission->getId(),
        );
    }

    /** @param array<string, mixed> $payload */
    private function submitImprove(array $payload, User $by): ContributionReceipt
    {
        $item = $this->em->find(Item::class, (int) ($payload['_item_id'] ?? 0));
        if (null === $item) {
            throw new \InvalidArgumentException('improve requires a valid _item_id');
        }

        /** @var array<string, mixed> $details */
        $details = (array) ($payload['details'] ?? []);
        /** @var array<string, mixed> $extras */
        $extras = (array) ($payload['extras'] ?? []);
        // Keep empty values here (do not array_filter): an emptied prefilled
        // field must survive as a removal. The change loop below normalises
        // '', null and [] to null and records the change as was -> null.
        $proposed = $details + $extras;

        // Climb shape (route/grad/steep) is a top-level hidden field on
        // ImproveType, not nested under details/extras (see ClimbGeometry).
        // Merge it into $proposed so a shape edit is recorded in $changes and
        // applied to $attributes on approve, the same as submitClimb.
        try {
            foreach (ClimbGeometry::fromPayload($payload) as $k => $v) {
                $proposed[$k] = $v;
            }
        } catch (\InvalidArgumentException) {
            $this->reject('contribute.error.invalid_geometry', 'route');
        }

        $currentAttrs = $item->getAttributes();
        $changes = [];
        $attributes = [];
        foreach ($proposed as $field => $rawNow) {
            // Normalise empty values ('', null, []) to null so clearing a
            // prefilled field is recorded as a removal, while a field that
            // was already empty records no phantom change.
            $now = self::normalizeEmpty($rawNow);
            // The name pseudo-field lives on Item::name, never in attributes
            // (see Item::NAME_FIELD). Comparing it against $currentAttrs would
            // always see null and wrongly record an unchanged name as a change.
            $was = Item::NAME_FIELD === $field ? $item->getName() : ($currentAttrs[$field] ?? null);
            if (self::normalizeEmpty($was) !== $now) {
                $changes[$field] = ['was' => $was, 'now' => $now];
            }
            if (null !== $now) {
                $attributes[$field] = $now;
            }
        }

        // Items may be Points, LineStrings (road surfaces) or Polygons. Never
        // assume a flat [lng,lat] pair. Derive a representative point (the
        // first vertex) so a segment edit is not stranded at Point(1 1).
        [$lng, $lat] = self::representativePoint((string) $item->getGeom());

        $draft = new SubmissionDraft(
            type: ItemType::fromParam($item->getLetter()),
            title: $item->getName(),
            lat: (float) $lat,
            lng: (float) $lng,
            attributes: $attributes,
            itemId: $item->getId(),
        );

        // Pass the computed was/now map into submitDraft so it is set inside
        // the same transaction, with no second flush outside wrapInTransaction.
        $submission = $this->submitDraft($draft, SubmissionType::Edit, $by, $payload, $changes);

        return new ContributionReceipt(
            'SUB-'.(string) $submission->getId(), 'improve', true, $submission->getCreatedAt(), $submission->getId(),
        );
    }

    /**
     * @param array<string, mixed>                         $rawPayload
     * @param array<string, array{was: mixed, now: mixed}> $changes    ready was/now
     *                                                                 map for Edit
     *                                                                 submissions
     *                                                                 (ignored for
     *                                                                 NewItem, which
     *                                                                 derives it from
     *                                                                 the draft
     *                                                                 attributes)
     */
    public function submitDraft(SubmissionDraft $draft, SubmissionType $type, User $by, array $rawPayload = [], array $changes = []): Submission
    {
        $limiter = $this->contributionSubmitLimiter->create('user-'.(string) $by->getId());
        if (!$limiter->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(null, 'contribute.error.rate_limited');
        }

        $violations = $this->validator->validate($draft);
        if (\count($violations) > 0) {
            throw new ValidationFailedException($draft, $violations);
        }

        $geo = $this->resolver->resolve($draft->lat, $draft->lng);
        $point = json_encode(['type' => 'Point', 'coordinates' => [$draft->lng, $draft->lat]], \JSON_THROW_ON_ERROR);

        return $this->em->wrapInTransaction(function () use ($draft, $type, $by, $rawPayload, $geo, $point, $changes): Submission {
            $submission = (new Submission())
                ->setType($type)
                ->setLetter($draft->type->letter())
                ->setItemId($draft->itemId)
                ->setUserId((int) $by->getId())
                ->setStatus(SubmissionStatus::Pending)
                ->setTitle($draft->title)
                ->setGeom($point)
                ->setCountryCode($geo['countryCode'])
                ->setRegionId($geo['regionId'])
                ->setChanges(SubmissionType::NewItem === $type
                    ? array_map(static fn (mixed $v): array => ['was' => null, 'now' => $v], $draft->attributes)
                    : $changes /* Edit passes a ready was/now map */)
                ->setPayload($rawPayload);
            $this->em->persist($submission);
            $this->em->flush();

            if (SubmissionType::NewItem === $type) {
                $item = (new Item())
                    ->setLetter($draft->type->letter())
                    ->setName($draft->title)
                    ->setGeom($point)
                    ->setCountryCode($geo['countryCode'])
                    ->setRegionId($geo['regionId'])
                    ->setState(ItemState::Submitted)
                    ->setSource(ItemSource::User)
                    ->setSourceRef('sub:'.(string) $submission->getId())
                    ->setAttributes($draft->attributes);
                $this->em->persist($item);
                $this->em->flush();
                $submission->setItemId($item->getId());
                $this->em->flush();
            }

            return $submission;
        });
    }

    /**
     * Throw a form-surfaceable validation error. The controller renders each
     * violation message as a FormError, so intake rejections read like every
     * other field error rather than a 500.
     */
    private function reject(string $message, string $field): never
    {
        throw new ValidationFailedException($message, new ConstraintViolationList([new ConstraintViolation($message, $message, [], $message, $field, null)]));
    }

    /** Collapse '' / null / [] to null; leave every other value untouched. */
    private static function normalizeEmpty(mixed $v): mixed
    {
        return (null === $v || '' === $v || [] === $v) ? null : $v;
    }

    /**
     * First coordinate of any GeoJSON geometry, as [lng, lat]. Points,
     * LineStrings and Polygons alike descend to the first numeric pair.
     *
     * @return array{0: float, 1: float}
     */
    private static function representativePoint(string $geomJson): array
    {
        /** @var array{coordinates?: mixed} $decoded */
        $decoded = json_decode($geomJson, true, 512, \JSON_THROW_ON_ERROR);

        return self::firstPair($decoded['coordinates'] ?? null);
    }

    /** @return array{0: float, 1: float} */
    private static function firstPair(mixed $coords): array
    {
        if (\is_array($coords) && \array_key_exists(0, $coords) && \array_key_exists(1, $coords)
            && !\is_array($coords[0]) && is_numeric($coords[0]) && is_numeric($coords[1])) {
            return [(float) $coords[0], (float) $coords[1]];
        }
        if (\is_array($coords) && isset($coords[0])) {
            return self::firstPair($coords[0]);
        }

        throw new \InvalidArgumentException('item geometry has no usable coordinate');
    }
}
