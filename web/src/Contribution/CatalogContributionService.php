<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Contribution;

use App\Catalog\Entity\Item;
use App\Catalog\Entity\Submission;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\ItemType;
use App\Catalog\LocationMode;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use App\Elevation\ClimbProfiler;
use App\Entity\User;
use App\Media\MediaClaimService;
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
        // No fAvg and no fMax: both gradients are measured from the drawn line,
        // never typed. The average is the editor's ascent-only figure
        // (applyDerivedAverage) and the maximum is read off the steepest-ramp
        // marker (deriveMaxGradient). See CatalogField::$derived.
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
        private readonly MediaClaimService $mediaClaims,
        private readonly ClimbProfiler $profiler,
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
            'add' => $this->submitAdd($payload, $by),
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
        $attributes = $this->deriveClimbProfile($attributes);

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

    /**
     * "Add a new place" — the generic NewItem intake for every non-climb,
     * non-route type (moderation-and-contribution.md §1.1 mode=add, §3.3).
     * Mirrors submitClimb's contract: reject-don't-coerce coordinates, the
     * form's constraints already validated field values, and submitDraft
     * owns the rate limiter, spatial resolution, and the Submitted item row.
     *
     * @param array<string, mixed> $payload
     */
    private function submitAdd(array $payload, User $by): ContributionReceipt
    {
        $type = ItemType::tryFrom((string) ($payload['type'] ?? ''));
        if (null === $type || \in_array($type, [ItemType::Climbs, ItemType::QualityRides], true)) {
            throw new \InvalidArgumentException('add requires a non-climb, non-route catalog type');
        }
        // A drawn segment IS a location, so a segment-located type does not
        // need a separate pin. The rider places a start and an end (A · road
        // surface is the only such type today) and the wizard fills `segment`;
        // requiring lat/lng as well made a complete submission fail with
        // "set the location on the map", pointing at a pin the form never
        // asked for.
        //
        // Derived here rather than in the client because lat/lng are not
        // decoration: RegionResolver turns them into the region whose
        // moderators see this submission. The stretch's START is the
        // representative point — the same choice submitImprove already makes
        // for an existing segment item (representativePoint takes the first
        // vertex).
        if (LocationMode::Segment === $type->locationMode()
            && !is_numeric($payload['lat'] ?? null)
            && \is_string($payload['segment'] ?? null) && '' !== $payload['segment']
        ) {
            $start = $this->decodeSegment((string) $payload['segment'])['a'];
            $payload['lng'] = $start[0];
            $payload['lat'] = $start[1];
        }
        if (!is_numeric($payload['lat'] ?? null) || !is_numeric($payload['lng'] ?? null)) {
            $this->reject('contribute.error.invalid_location', 'lat');
        }

        /** @var array<string, mixed> $details */
        $details = (array) ($payload['details'] ?? []);
        /** @var array<string, mixed> $extras */
        $extras = (array) ($payload['extras'] ?? []);
        $proposed = $details + $extras;

        // The name pseudo-field becomes the submission title / Item::name —
        // never an attribute (Item::NAME_FIELD, same rule as submitImprove).
        $name = trim((string) ($proposed[Item::NAME_FIELD] ?? ''));
        unset($proposed[Item::NAME_FIELD]);
        if ('' === $name) {
            // The form's NotBlank already guards this; a hand-crafted POST
            // must not mint an unnamed item.
            $this->reject('contribute.error.name_required', 'details');
        }

        $attributes = [];
        foreach ($proposed as $field => $raw) {
            $now = self::normalizeEmpty($raw);
            if (null !== $now) {
                $attributes[$field] = $now;
            }
        }

        // Segment-located types: the two drawn endpoints become a real
        // attribute (unlike edits, where geometry changes are payload-only —
        // a NEW segment item has no other geometry to fall back on).
        if (LocationMode::Segment === $type->locationMode()) {
            $rawSegment = $payload['segment'] ?? null;
            if (\is_string($rawSegment) && '' !== $rawSegment) {
                $attributes['segment'] = $this->decodeSegment($rawSegment);
            }
        }

        // Materialize-on-edit (osm-data-architecture.md §6): the controller
        // re-validated the ref against the coverage cache; here the only
        // extra invariant is one item per OSM ref — a second materialization
        // (double submit, or a race with another rider) must not mint a twin.
        $osmRef = null;
        $rawRef = $payload['_osm_ref'] ?? null;
        if (\is_string($rawRef) && '' !== $rawRef) {
            if (1 !== preg_match('~^(node|way)/\d{1,16}$~', $rawRef)) {
                throw new \InvalidArgumentException('malformed OSM ref');
            }
            $taken = $this->em->getRepository(Item::class)->findOneBy([
                'sourceRef' => $rawRef,
                'state' => [ItemState::Submitted, ItemState::Unverified, ItemState::Verified],
            ]);
            if (null !== $taken) {
                $this->reject('contribute.error.already_materialized', 'details');
            }
            $osmRef = $rawRef;
        }

        $draft = new SubmissionDraft(
            type: $type,
            title: $name,
            lat: (float) $payload['lat'],
            lng: (float) $payload['lng'],
            attributes: $attributes,
            osmRef: $osmRef,
        );

        $submission = $this->submitDraft($draft, SubmissionType::NewItem, $by, $payload);

        return new ContributionReceipt(
            'SUB-'.(string) $submission->getId(), 'add', true, $submission->getCreatedAt(), $submission->getId(),
        );
    }

    /**
     * Decode + bounds-check the wizard's {"a":[lng,lat],"b":[lng,lat]} JSON.
     *
     * @return array{a: array{float, float}, b: array{float, float}}
     */
    private function decodeSegment(string $raw): array
    {
        $decoded = json_decode($raw, true);
        $pair = static function (mixed $p): ?array {
            if (!\is_array($p) || !array_is_list($p) || 2 !== \count($p)
                || !is_numeric($p[0]) || !is_numeric($p[1])) {
                return null;
            }
            $lng = (float) $p[0];
            $lat = (float) $p[1];

            return ($lng >= -180 && $lng <= 180 && $lat >= -90 && $lat <= 90) ? [$lng, $lat] : null;
        };

        $a = \is_array($decoded) ? $pair($decoded['a'] ?? null) : null;
        $b = \is_array($decoded) ? $pair($decoded['b'] ?? null) : null;
        if (null === $a || null === $b) {
            $this->reject('contribute.error.invalid_geometry', 'segment');
        }

        return ['a' => $a, 'b' => $b];
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
        /* Max gradient follows the steepest-ramp marker — but only when the
           marker actually MOVED.

           Deriving it unconditionally re-introduces the phantom change this
           class just learned to avoid: a seeded climb stores the editorial
           "~20% (mid-climb ramp)" while the marker reads "~20%", so every edit
           would record a maxGradient change nobody made, and would quietly
           overwrite the editorial text on the way past. A rider who drags the
           marker IS restating the max gradient; a rider who leaves it alone is
           not. */
        $proposed = $this->deriveClimbProfile($proposed, $item->getAttributes());

        $currentAttrs = $item->getAttributes();
        $changes = [];
        $attributes = [];
        foreach ($proposed as $field => $rawNow) {
            // Normalise empty values ('', null, []) to null so clearing a
            // prefilled field is recorded as a removal, while a field that
            // was already empty records no phantom change.
            $now = self::normalizeEmpty($rawNow);
            // The name pseudo-field lives on Item::name, never in attributes
            // (see Item::NAME_FIELD) — letting it through would submit `name`
            // as an attribute key no letter's vocabulary allows, and the whole
            // edit would be rejected as unknown. It travels as a CHANGE only,
            // which is what ModerationService::applyEdit reads to setName().
            //
            // An emptied box means "leave the name alone", never "clear the
            // name": editing offers the name prefilled (ImproveType) so a rider
            // can correct it, and a place with no name at all is a different
            // proposition from a place whose name somebody deleted.
            if (Item::NAME_FIELD === $field) {
                if (null !== $now && $item->getName() !== $now) {
                    $changes[$field] = ['was' => $item->getName(), 'now' => $now];
                }
                continue;
            }
            $was = $currentAttrs[$field] ?? null;
            if (!self::sameValue(self::normalizeEmpty($was), $now)) {
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

        // An edit that changes nothing is not a contribution: it costs a
        // curator a queue row to read, tells the rider's own dashboard a
        // suggestion is pending, and applies nothing on approve. The wizard
        // walks straight from a prefilled form to Submit, so this is easy to
        // do by accident — say so plainly instead of recording it.
        //
        // "Changed" is broader than the field diff: a photo, a photo link and
        // a moved pin are all real contributions on their own.
        if ([] === $changes
            && !self::hasMedia($payload)
            && !self::pinMoved($payload, (float) $lat, (float) $lng)
        ) {
            $this->reject('contribute.error.nothing_changed', 'details');
        }

        $draft = new SubmissionDraft(
            type: ItemType::fromParam($item->getLetter()),
            title: $item->getName(),
            lat: (float) $lat,
            lng: (float) $lng,
            attributes: $attributes,
            itemId: $item->getId(),
        );

        /* An undecided submission on this item by this rider is AMENDED, never
           duplicated (owner decision, 2026-08-04). A rider asked a question
           about their proposal goes back, revises it, and sends the same
           submission again — "updates their update". Filing a second one would
           leave the desk holding two competing proposals for one item with
           nothing to say which supersedes which, and would leave the curator's
           question attached to the abandoned one.

           The reference is deliberately unchanged, so the messages thread the
           rider and curator have already exchanged still names the thing they
           are talking about. It returns to `pending`: the ball is back with the
           curator, which is the same transition a needs-info reply makes. */
        $open = $this->openSubmissionFor((int) $item->getId(), $by);
        if (null !== $open) {
            // `changes` and `payload` are the whole of an edit submission — an
            // Edit carries no attributes column of its own; ModerationService
            // applies the was/now map on approve.
            $this->em->wrapInTransaction(function () use ($open, $changes, $payload): void {
                $open->setChanges($changes)
                    ->setPayload($payload)
                    ->setStatus(SubmissionStatus::Pending)
                    // The previous round's verdict belongs to the previous
                    // round: a stale "we need more information" note sitting on
                    // a freshly revised submission reads as a new complaint.
                    ->setDecisionNote(null)
                    ->setDecidedAt(null)
                    ->setDecidedBy(null);
            });

            return new ContributionReceipt(
                'SUB-'.(string) $open->getId(), 'improve', true, $open->getCreatedAt(), $open->getId(),
            );
        }

        // Pass the computed was/now map into submitDraft so it is set inside
        // the same transaction, with no second flush outside wrapInTransaction.
        $submission = $this->submitDraft($draft, SubmissionType::Edit, $by, $payload, $changes);

        return new ContributionReceipt(
            'SUB-'.(string) $submission->getId(), 'improve', true, $submission->getCreatedAt(), $submission->getId(),
        );
    }

    /**
     * The rider's own submission on this item that has not been decided yet.
     *
     * A rider who has already proposed a change and is asked a question about
     * it must be able to go back and REVISE it — "update their update". Without
     * this they can only file a second submission, and the desk then holds two
     * competing proposals for one item with nothing to say which supersedes
     * which (owner decision, 2026-08-04).
     *
     * `pending` and `needs_info` are exactly the undecided states. An approved
     * or rejected submission is history and is never amended: a further change
     * to that item is a new contribution, which is what it is.
     *
     * @api Read by ContributeController to prefill the wizard, and by
     *      improve() to amend rather than duplicate.
     */
    public function openSubmissionFor(int $itemId, User $by): ?Submission
    {
        /* A QueryBuilder rather than findOneBy([... 'status' => [A, B]]): the
           column maps through `enumType`, and an ARRAY of backed enums in
           findOneBy criteria leans on Doctrine converting each element. It
           does, but this is the query that decides whether a rider's revision
           lands on their existing submission or forks a second one, so it says
           what it means with scalar values and an explicit ordering.

           Newest first: if a rider somehow holds two open submissions on one
           item (possible before this method existed), the one they are looking
           at is the most recent. */
        return $this->em->createQueryBuilder()
            ->select('s')
            ->from(Submission::class, 's')
            ->where('s.itemId = :item')
            ->andWhere('s.userId = :uid')
            ->andWhere('s.status IN (:open)')
            ->setParameter('item', $itemId)
            ->setParameter('uid', (int) $by->getId())
            ->setParameter('open', [SubmissionStatus::Pending->value, SubmissionStatus::NeedsInfo->value])
            ->orderBy('s.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
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
        // A segment-located item is a LINE, and storing it as a point would be
        // a quiet corruption rather than an approximation: CatalogProvider's
        // surfaceSegments() reads geom as a list of vertex pairs to build the
        // A layer's `path`, so a Point there yields nonsense for that row.
        //
        // The submission itself keeps the point geometry — the moderation desk
        // pins submissions on a map, and the start of the stretch is the right
        // pin — while the ITEM gets the line the rider drew.
        //
        // Straight a→b for now. A stretch of road bends, so the honest
        // long-term shape is the OSM way's own geometry clipped between the two
        // taps; the client has that geometry (it is in the tile) and the server
        // deliberately does not. Sending it is a contract change, and worth
        // making deliberately rather than inferring here.
        $segment = $draft->attributes['segment'] ?? null;
        $itemGeom = \is_array($segment) && isset($segment['a'], $segment['b'])
            ? json_encode(['type' => 'LineString', 'coordinates' => [$segment['a'], $segment['b']]], \JSON_THROW_ON_ERROR)
            : $point;

        return $this->em->wrapInTransaction(function () use ($draft, $type, $by, $rawPayload, $geo, $point, $itemGeom, $changes): Submission {
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
                // A materialized OSM object keeps its ref as source_ref
                // (source `osm`) — honest provenance, and the coverage layer
                // dedupes on exactly this key so the grey twin disappears
                // the moment this item serves.
                $item = (new Item())
                    ->setLetter($draft->type->letter())
                    ->setName($draft->title)
                    ->setGeom($itemGeom)
                    ->setCountryCode($geo['countryCode'])
                    ->setRegionId($geo['regionId'])
                    ->setState(ItemState::Submitted)
                    ->setSource(null !== $draft->osmRef ? ItemSource::Osm : ItemSource::User)
                    ->setSourceRef($draft->osmRef ?? 'sub:'.(string) $submission->getId())
                    ->setAttributes($draft->attributes);
                $this->em->persist($item);
                $this->em->flush();
                $submission->setItemId($item->getId());
                $this->em->flush();
            }

            // Photos ride the same intake transaction as the facts
            // (docs/specs/photo-uploads.md §4): a rejected photo list rolls the
            // whole submission back rather than leaving a half-attached
            // contribution.
            try {
                $this->mediaClaims->claim($rawPayload['mediaIds'] ?? null, $by, $submission);
            } catch (\InvalidArgumentException) {
                $this->reject('contribute.error.media_invalid', 'mediaIds');
            }
            $this->em->flush();

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

    /**
     * Does this edit carry a photo — an upload or a link?
     *
     * @param array<string, mixed> $payload
     */
    private static function hasMedia(array $payload): bool
    {
        return '' !== trim((string) ($payload['mediaIds'] ?? ''))
            || '' !== trim((string) ($payload['photoUrl'] ?? ''));
    }

    /**
     * Has the pin actually been moved, or is it sitting where it was?
     *
     * The wizard pre-places the pin at the item's own coordinates and posts
     * them back untouched, so an exact comparison would call every edit a
     * move. The tolerance is ~1 m: below that nobody moved anything, they
     * just opened the map.
     *
     * @param array<string, mixed> $payload
     */
    private static function pinMoved(array $payload, float $lat, float $lng): bool
    {
        if (!is_numeric($payload['lat'] ?? null) || !is_numeric($payload['lng'] ?? null)) {
            return false;
        }

        return abs((float) $payload['lat'] - $lat) > 1e-5
            || abs((float) $payload['lng'] - $lng) > 1e-5;
    }

    /** Collapse '' / null / [] to null; leave every other value untouched. */
    private static function normalizeEmpty(mixed $v): mixed
    {
        return (null === $v || '' === $v || [] === $v) ? null : $v;
    }

    /**
     * Is the proposed value the same fact as the stored one?
     *
     * `!==` is too literal for the values the climb editor round-trips. The
     * steepest marker is stored as `{at, pct}` and comes back as
     * `{at, pct, manual: false}` — the same marker in the same place at the
     * same percentage, described with one extra default. Compared strictly it
     * counted as a change, so EVERY climb edit recorded a phantom
     * `steep: ~20% at 50.4908, 5.7058 → ~20% at 50.4908, 5.7058`, and a curator
     * was asked to approve a difference that did not exist (owner-reported
     * 2026-08-04). Key order does the same thing: PHP compares string-keyed
     * arrays order-sensitively under `===`.
     *
     * So arrays are compared canonically — recursively key-sorted, with the
     * marker's optional `manual` flag defaulted. Scalars keep strict
     * comparison, where `'0'` and `0` and `false` are genuinely different
     * answers.
     */
    private static function sameValue(mixed $was, mixed $now): bool
    {
        if (\is_array($was) && \is_array($now)) {
            return self::canonical($was) === self::canonical($now);
        }

        return $was === $now;
    }

    /** Recursively key-sorted, with the steep marker's default filled in. */
    private static function canonical(mixed $v): string
    {
        if (\is_array($v)) {
            // The one documented default: an absent `manual` means "not placed
            // by hand", which is exactly what `manual: false` says.
            if (\array_key_exists('at', $v) && \array_key_exists('pct', $v)) {
                $v['manual'] = (bool) ($v['manual'] ?? false);
            }
            ksort($v);
            $out = [];
            foreach ($v as $k => $child) {
                $out[$k] = \is_array($child) ? json_decode(self::canonical($child), true) : $child;
            }
            $v = $out;
        }

        return json_encode($v, \JSON_UNESCAPED_UNICODE) ?: '';
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

    /**
     * Moves the editor's ascent-only average onto `avgGradient`, and drops the
     * transport key so `avg` never becomes an attribute in its own right.
     *
     * Unconditional, unlike deriveMaxGradient's caller-side gate. The average is
     * a function of the whole line, so when the line changes it MUST change --
     * that is the bug this closes: redrawing Roche-aux-Faucons from 1.75 km to
     * 4.35 km left `avgGradient` reading the 9% somebody typed in June
     * (owner-reported 2026-08-04). When the line is untouched the editor
     * recomputes the same value from the same samples, so no phantom change is
     * recorded.
     *
     * @param array<string, mixed> $attrs
     *
     * @return array<string, mixed>
     */
    /**
     * Measures the climb from its drawn line and writes every derived value.
     *
     * This used to set only the average and the maximum, and the rest —
     * `length`, `gain`, `binM`, `lineGrad`, `demSource` — arrived solely from
     * the catalogue sweep. So an approved redraw updated the gradients and the
     * profile but left the length behind: Côte de Stockeu displayed "1.0 km"
     * beside a chart whose own axis read 2.4 km (owner-reported 2026-08-05).
     * Anything derived from the line has to be derived *when the line changes*,
     * or the item is internally inconsistent until someone remembers to run a
     * command.
     *
     * No route, no change: a submission that does not touch the geometry leaves
     * every measurement exactly as it was.
     *
     * @param array<string, mixed>      $attrs
     * @param array<string, mixed>|null $current the item's present attributes, when editing
     *
     * @return array<string, mixed>
     */
    private function deriveClimbProfile(array $attrs, ?array $current = null): array
    {
        /* `avg` is a TRANSPORT key, never an attribute: the editor posts it and
           the profile below replaces it. Dropped first and unconditionally,
           because a submission whose elevation lookup fails must not leave it
           behind to be stored as though it were a field. */
        unset($attrs['avg']);

        $route = $attrs['route'] ?? ($current['route'] ?? null);
        if (!\is_array($route) || \count($route) < 2) {
            return $attrs;
        }
        /** @var list<array{0: float, 1: float}> $coords */
        $coords = array_values(array_map(
            static fn (array $p): array => [(float) $p[0], (float) $p[1]],
            array_filter($route, static fn ($p): bool => \is_array($p) && isset($p[0], $p[1])),
        ));

        /* A hand-placed steepest marker is the rider's: its position is kept and
           only the number under it is re-read (climb-elevation.md §5). */
        $steep = $attrs['steep'] ?? ($current['steep'] ?? null);
        $manualAt = (\is_array($steep) && true === ($steep['manual'] ?? false) && isset($steep['at'][0], $steep['at'][1]))
            ? [(float) $steep['at'][0], (float) $steep['at'][1]]
            : null;

        $p = $this->profiler->profile($coords, $manualAt);
        if (null === $p) {
            // No elevation, no numbers — never a guess (§2d). The geometry still
            // submits: the line is the contribution, the profile derives from it.
            return $attrs;
        }

        $attrs['length'] = round($p['length']);
        $attrs['gain'] = round($p['gain']);
        $attrs['avgGradient'] = $p['avgGradient'];
        $attrs['maxGradient'] = $p['maxGradient'];
        $attrs['grad'] = $p['grad'];
        $attrs['lineGrad'] = $p['lineGrad'];
        $attrs['binM'] = $p['binM'];
        $attrs['demSource'] = $p['demSource'];
        if (null !== $manualAt) {
            $attrs['steep'] = ['at' => $manualAt, 'pct' => $p['sustainedAtSteep'] ?? ($steep['pct'] ?? ''), 'manual' => true];
        } else {
            $attrs['steep'] = $p['steep'];
        }
        // A stored display string nothing recomputes drifts the moment a climb
        // is redrawn; the drawer composes that line at render time (§4).
        unset($attrs['headline']);

        return $attrs;
    }
}
