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
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The real contribution intake (replaces the deleted honest-stub service,
 * keeps the interface). 'climb' → NewItem: item row (state=submitted, source=user,
 * source_ref sub:<id>) + submission, one transaction. 'improve' → Edit: bound
 * to a real Item (`_item_id`), snapshots only the fields whose proposed value
 * differs from the item's current attribute value ({field: {was, now}}) —
 * unchanged fields are never recorded. 'vote' passes through unpersisted —
 * voting is verification-gate machinery (spec non-goal), the receipt stays
 * honest about it.
 *
 * @api Autowired via ContributionStubInterface.
 */
final class CatalogContributionService implements ContributionStubInterface
{
    /**
     * AddClimbType field → registry attribute key (letter B vocabulary, see
     * CatalogFormRegistry::for(Climbs) + AttributeVocabulary::allowedKeys('B')).
     * The registry's Climbs fields are name/surface/avgGradient/maxGradient/
     * correction/waterOnClimb/hairpins/shade, plus the 'sq' and 'tr'
     * harvest-vocabulary extras (surface quality / traffic, per the wallonia
     * export shape in atlas/demo/climbs-data.js). AddClimbType's fLen/fGain/
     * fOsm have no matching registry attribute (climb length/elevation gain
     * are derived from geometry per the B-climbs spec, and "already in OSM?"
     * is submission-only metadata) — they stay out of `attributes` but are
     * still preserved verbatim in the submission's raw `payload` for
     * moderator review.
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
            default => new ContributionReceipt(
                'CC-'.strtoupper(bin2hex(random_bytes(6))), $kind, false, new \DateTimeImmutable(),
            ),
        };
    }

    /** @param array<string, mixed> $payload */
    private function submitClimb(array $payload, User $by): ContributionReceipt
    {
        $attributes = [];
        foreach (self::CLIMB_FIELDS as $formKey => $attrKey) {
            $v = $payload[$formKey] ?? null;
            if (null !== $v && '' !== $v) {
                $attributes[$attrKey] = \is_float($v) || \is_int($v) ? $v : (string) $v;
            }
        }

        try {
            $attributes += ClimbGeometry::fromPayload($payload);
        } catch (\InvalidArgumentException) {
            // Malformed editor output — drop the shape, keep the rest of the
            // submission. A climb with no route still lands as a point; the
            // rider can re-draw via the edit flow. (Do not 500 on bad JSON.)
        }

        $draft = new SubmissionDraft(
            type: ItemType::Climbs,
            title: (string) ($payload['fName'] ?? ''),
            lat: (float) ($payload['lat'] ?? 0.0),
            lng: (float) ($payload['lng'] ?? 0.0),
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
        $proposed = array_filter(
            $details + $extras,
            static fn (mixed $v): bool => null !== $v && '' !== $v,
        );

        // Climb shape (route/grad/steep) is carried as TOP-LEVEL hidden fields
        // by ImproveType (not nested under details/extras — see ClimbGeometry),
        // written by the shared three-point editor (Task 5). Decode + merge it
        // into $proposed so a shape edit shows in $changes (was/now, array
        // `!==` comparison below) and is applied to $attributes on approve,
        // the same as the add-climb path (submitClimb).
        try {
            foreach (ClimbGeometry::fromPayload($payload) as $k => $v) {
                $proposed[$k] = $v;
            }
        } catch (\InvalidArgumentException) {
            // Malformed/absent editor output — keep the rest of the edit.
        }

        $currentAttrs = $item->getAttributes();
        $changes = [];
        $attributes = [];
        foreach ($proposed as $field => $now) {
            // 'name' is a pseudo-field: it lives on Item::name, never in
            // attributes (ModerationService::applyEdit treats it the same
            // way) — comparing it against $currentAttrs would always see
            // null and wrongly record an unchanged name as a "change".
            $was = 'name' === $field ? $item->getName() : ($currentAttrs[$field] ?? null);
            if ($was !== $now) {
                $changes[$field] = ['was' => $was, 'now' => $now];
            }
            $attributes[$field] = $now;
        }

        [$lng, $lat] = json_decode((string) $item->getGeom(), true, 512, \JSON_THROW_ON_ERROR)['coordinates'];

        $draft = new SubmissionDraft(
            type: ItemType::fromParam($item->getLetter()),
            title: $item->getName(),
            lat: (float) $lat,
            lng: (float) $lng,
            attributes: $attributes,
            itemId: $item->getId(),
        );

        // submitDraft stores $draft->attributes as `changes` for Edit — pass
        // the computed was/now map through the dedicated path instead:
        $submission = $this->submitDraft($draft, SubmissionType::Edit, $by, $payload);
        $submission->setChanges($changes);
        $this->em->flush();

        return new ContributionReceipt(
            'SUB-'.(string) $submission->getId(), 'improve', true, $submission->getCreatedAt(), $submission->getId(),
        );
    }

    /** @param array<string, mixed> $rawPayload */
    public function submitDraft(SubmissionDraft $draft, SubmissionType $type, User $by, array $rawPayload = []): Submission
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

        return $this->em->wrapInTransaction(function () use ($draft, $type, $by, $rawPayload, $geo, $point): Submission {
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
                    : $draft->attributes /* Task 4 passes a ready was/now map here */)
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
}
