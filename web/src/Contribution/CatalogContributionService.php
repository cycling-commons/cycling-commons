<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Contribution;

use App\Catalog\Entity\Item;
use App\Catalog\Entity\Submission;
use App\Catalog\Import\OutboundLinks;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\ItemType;
use App\Catalog\Links\LinkVerdictStore;
use App\Catalog\Links\SafeBrowsing;
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
 * Contribute-form payloads → catalog submissions.
 *
 * @see docs/specs/moderation-and-contribution.md
 *
 * @api
 */
final class CatalogContributionService implements ContributionStubInterface
{
    /**
     * AddClimbType field names → registry attribute keys.
     *
     * @see docs/specs/edit-items/B-climbs.md
     */
    private const array CLIMB_FIELDS = [
        // Gradients are measured from the line, never typed (docs/specs/climb-elevation.md §4).
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
        private readonly SafeBrowsing $safeBrowsing,
        private readonly LinkVerdictStore $linkVerdicts,
    ) {
    }

    /** Payload keys this service refuses. `photoUrl` is never accepted. */
    private const array REFUSED_KEYS = ['photoUrl'];

    #[\Override]
    public function submit(string $kind, array $payload, ?User $by): ContributionReceipt
    {
        if (null === $by) {
            throw new \LogicException('Contributions require an authenticated user.');
        }

        // Drop refused keys silently; leftover markup must not fail the submission.
        foreach (self::REFUSED_KEYS as $key) {
            unset($payload[$key]);
        }

        return match ($kind) {
            'climb' => $this->submitClimb($payload, $by),
            'add' => $this->submitAdd($payload, $by),
            'improve' => $this->submitImprove($payload, $by),
            // Voting is verification-gate machinery, not catalog intake.
            default => new ContributionReceipt(
                'CC-'.strtoupper(bin2hex(random_bytes(6))), $kind, false, new \DateTimeImmutable(),
            ),
        };
    }

    /** @param array<string, mixed> $payload */
    private function submitClimb(array $payload, User $by): ContributionReceipt
    {
        // Reject rather than coerce: a missing coordinate must not become 0.0.
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

        // Surface malformed editor output as a form error, not a silent drop.
        try {
            $attributes += ClimbGeometry::fromPayload($payload);
        } catch (\InvalidArgumentException) {
            $this->reject('contribute.error.invalid_geometry', 'route');
        }
        $attributes = $this->deriveClimbProfile($attributes);

        $draft = new SubmissionDraft(
            type: ItemType::Climbs,
            title: (string) ($payload['fName'] ?? ''),
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
     * Generic NewItem intake (docs/specs/moderation-and-contribution.md §1.1, §3.3).
     *
     * @param array<string, mixed> $payload
     */
    private function submitAdd(array $payload, User $by): ContributionReceipt
    {
        $type = ItemType::tryFrom((string) ($payload['type'] ?? ''));
        if (null === $type || \in_array($type, [ItemType::Climbs, ItemType::QualityRides], true)) {
            throw new \InvalidArgumentException('add requires a non-climb, non-route catalog type');
        }
        // Segment-located types use the stretch start as the pin (docs/specs/moderation-and-contribution.md §1.3).
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

        // Name becomes the title, never an attribute (Item::NAME_FIELD).
        $name = trim((string) ($proposed[Item::NAME_FIELD] ?? ''));
        unset($proposed[Item::NAME_FIELD]);
        if ('' === $name) {
            // A hand-crafted POST must not mint an unnamed item.
            $this->reject('contribute.error.name_required', 'details');
        }

        $proposed = $this->decodeLinks($proposed);

        $attributes = [];
        foreach ($proposed as $field => $raw) {
            $now = self::normalizeEmpty($raw);
            if (null !== $now) {
                $attributes[$field] = $now;
            }
        }

        // New segment items store geometry as an attribute; edits keep it payload-only.
        if (LocationMode::Segment === $type->locationMode()) {
            $rawSegment = $payload['segment'] ?? null;
            if (\is_string($rawSegment) && '' !== $rawSegment) {
                $attributes['segment'] = $this->decodeSegment($rawSegment);
            }
            // Way refs the prefilled run spans, so curatedRefs() retires every covered way.
            $rawSpanned = $payload['_ways_spanned'] ?? null;
            if (\is_string($rawSpanned) && '' !== $rawSpanned) {
                $spanned = array_values(array_unique(array_filter(
                    explode(',', $rawSpanned),
                    static fn (string $r): bool => 1 === preg_match('~^way/\d{1,16}$~', $r),
                )));
                if ([] !== $spanned) {
                    $attributes['waysSpanned'] = \array_slice($spanned, 0, 120);
                }
            }
        }

        // One item per OSM ref (docs/specs/osm-data-architecture.md §6).
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
            /* A rejected row is revived, not twinned (docs/specs/moderation-and-contribution.md §3.3). */
            $osmRef = $rawRef;
        }

        // `via` is the intake channel; only known values are honoured.
        $source = 'scout' === ($payload['via'] ?? null) ? ItemSource::Scout : null;

        $draft = new SubmissionDraft(
            type: $type,
            title: $name,
            lat: (float) $payload['lat'],
            lng: (float) $payload['lng'],
            attributes: $attributes,
            osmRef: $osmRef,
            source: $source,
        );

        $submission = $this->submitDraft($draft, SubmissionType::NewItem, $by, $payload);

        return new ContributionReceipt(
            'SUB-'.(string) $submission->getId(), 'add', true, $submission->getCreatedAt(), $submission->getId(),
        );
    }

    /** Longest road-following path a surface stretch may carry. */
    private const int MAX_SEGMENT_POINTS = 3000;

    /** Max snap drift from the rider's pin; beyond this the path is refused. */
    private const float MAX_SNAP_DRIFT_M = 1000.0;

    /**
     * Decode + bounds-check the wizard's segment JSON.
     *
     * @return array{a: array{float, float}, b: array{float, float}, line?: list<array{float, float}>}
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

        $segment = ['a' => $a, 'b' => $b];
        $rawLine = $decoded['line'] ?? null;
        if (null === $rawLine) {
            return $segment;
        }

        if (!\is_array($rawLine) || !array_is_list($rawLine)
            || \count($rawLine) < 2 || \count($rawLine) > self::MAX_SEGMENT_POINTS) {
            $this->reject('contribute.error.invalid_geometry', 'segment');
        }
        $line = [];
        foreach ($rawLine as $point) {
            $valid = $pair($point);
            if (null === $valid) {
                $this->reject('contribute.error.invalid_geometry', 'segment');
            }
            $line[] = $valid;
        }
        // Both ends must belong to the rider's stretch; otherwise `line` is an open channel.
        if (self::metres($line[0], $a) > self::MAX_SNAP_DRIFT_M
            || self::metres($line[\count($line) - 1], $b) > self::MAX_SNAP_DRIFT_M) {
            $this->reject('contribute.error.invalid_geometry', 'segment');
        }
        $segment['line'] = $line;

        return $segment;
    }

    /**
     * Great-circle metres between two [lng, lat] pairs.
     *
     * @param array{float, float} $p
     * @param array{float, float} $q
     */
    private static function metres(array $p, array $q): float
    {
        $lat = deg2rad(($p[1] + $q[1]) / 2);
        $dx = deg2rad($q[0] - $p[0]) * cos($lat);
        $dy = deg2rad($q[1] - $p[1]);

        return 6371000.0 * sqrt($dx * $dx + $dy * $dy);
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
        // Keep empty values: an emptied prefilled field must survive as a removal.
        $proposed = $details + $extras;

        // Climb shape is a top-level hidden field; merge so a shape edit is recorded.
        try {
            foreach (ClimbGeometry::fromPayload($payload) as $k => $v) {
                $proposed[$k] = $v;
            }
        } catch (\InvalidArgumentException) {
            $this->reject('contribute.error.invalid_geometry', 'route');
        }

        // Segment is top-level too; only letter A. Unchanged prefill records nothing.
        $rawSegment = $payload['segment'] ?? null;
        if ('A' === $item->getLetter() && \is_string($rawSegment) && '' !== $rawSegment) {
            $proposed['segment'] = $this->decodeSegment($rawSegment);
        }
        /* Max gradient follows the marker only when the marker moved (docs/specs/climb-elevation.md §5). */
        $proposed = $this->deriveClimbProfile($proposed, $item->getAttributes());
        /* Decode links before the diff: stored array vs posted JSON would look like a change. */
        $proposed = $this->decodeLinks($proposed);

        $currentAttrs = $item->getAttributes();
        $changes = [];
        $attributes = [];
        foreach ($proposed as $field => $rawNow) {
            // Normalise '', null, [] to null so clearing a field is a removal.
            $now = self::normalizeEmpty($rawNow);
            // Name lives on Item::name, never in attributes. Empty means leave it alone.
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

        // Representative point is the first vertex; never assume a Point.
        [$lng, $lat] = self::representativePoint((string) $item->getGeom());

        // No field/photo/pin change is not a contribution.
        $moved = self::pinMoved($payload, (float) $lat, (float) $lng);
        if ([] === $changes && !self::hasMedia($payload) && !$moved) {
            $this->reject('contribute.error.nothing_changed', 'details');
        }

        /* A moved pin is a CHANGE, not merely permission to submit. */
        $newLat = (float) $lat;
        $newLng = (float) $lng;
        if ($moved) {
            $newLat = (float) $payload['lat'];
            $newLng = (float) $payload['lng'];
            $changes[Item::LOCATION_FIELD] = [
                'was' => self::formatPoint((float) $lat, (float) $lng),
                'now' => self::formatPoint($newLat, $newLng),
            ];
        }

        $draft = new SubmissionDraft(
            type: ItemType::fromParam($item->getLetter()),
            /* Nameless places use `_title_fallback`; the item is not renamed by it. */
            title: '' !== $item->getName()
                ? $item->getName()
                : trim((string) ($payload['_title_fallback'] ?? '')),
            // Submission sits where the rider put it, not where the item still is.
            lat: $newLat,
            lng: $newLng,
            attributes: $attributes,
            itemId: $item->getId(),
        );

        /* Open submission on this item is amended, never duplicated (docs/specs/moderation-and-contribution.md §7.3b). */
        $open = $this->openSubmissionFor((int) $item->getId(), $by);
        if (null !== $open) {
            // Edit carries no attributes column; apply the was/now map on approve.
            $this->em->wrapInTransaction(function () use ($open, $changes, $payload): void {
                $open->setChanges($changes)
                    ->setPayload($payload)
                    ->setStatus(SubmissionStatus::Pending)
                    // Clear the previous round's verdict from a freshly revised submission.
                    ->setDecisionNote(null)
                    ->setDecidedAt(null)
                    ->setDecidedBy(null);
            });

            return new ContributionReceipt(
                'SUB-'.(string) $open->getId(), 'improve', true, $open->getCreatedAt(), $open->getId(),
            );
        }

        $submission = $this->submitDraft($draft, SubmissionType::Edit, $by, $payload, $changes);

        return new ContributionReceipt(
            'SUB-'.(string) $submission->getId(), 'improve', true, $submission->getCreatedAt(), $submission->getId(),
        );
    }

    /**
     * The rider's own undecided submission on this item, if any.
     *
     * @api
     */
    public function openSubmissionFor(int $itemId, User $by): ?Submission
    {
        /* QueryBuilder with scalar enum values; newest first if two open rows exist. */
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
     * @param array<string, array{was: mixed, now: mixed}> $changes    Edit was/now map (ignored for NewItem)
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
        // Segment items store a LineString; the submission keeps the start point.
        $segment = $draft->attributes['segment'] ?? null;
        $itemGeom = $point;
        if (\is_array($segment) && isset($segment['a'], $segment['b'])) {
            /** @var list<array{float, float}> $path */
            $path = \is_array($segment['line'] ?? null) ? $segment['line'] : [$segment['a'], $segment['b']];
            $itemGeom = json_encode(['type' => 'LineString', 'coordinates' => $path], \JSON_THROW_ON_ERROR);
        }

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
                /* Revive a rejected/retired OSM row rather than minting a twin. */
                $item = null !== $draft->osmRef
                    ? $this->em->getRepository(Item::class)->findOneBy([
                        'sourceRef' => $draft->osmRef,
                        'letter' => $draft->type->letter(),
                        'state' => [ItemState::Rejected, ItemState::Retired],
                    ])
                    : null;
                // Materialized OSM objects keep source_ref so coverage can dedupe.
                $item ??= new Item();
                $item
                    ->setLetter($draft->type->letter())
                    ->setName($draft->title)
                    ->setGeom($itemGeom)
                    ->setCountryCode($geo['countryCode'])
                    ->setRegionId($geo['regionId'])
                    ->setState(ItemState::Submitted)
                    ->setSource($draft->source ?? (null !== $draft->osmRef ? ItemSource::Osm : ItemSource::User))
                    ->setSourceRef($draft->osmRef ?? 'sub:'.(string) $submission->getId())
                    ->setAttributes($draft->attributes);
                $this->em->persist($item);
                $this->em->flush();
                $submission->setItemId($item->getId());
                $this->em->flush();
            }

            // Photos ride the same intake transaction (docs/specs/photo-uploads.md §4).
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
     * Decode the links editor JSON (docs/specs/catalog-data-model.md §7).
     *
     * @param array<string, mixed> $proposed
     *
     * @return array<string, mixed>
     */
    private function decodeLinks(array $proposed): array
    {
        $raw = $proposed['links'] ?? null;
        // Absent, or already an array from an internal caller.
        if (!\is_string($raw)) {
            return $proposed;
        }
        if ('' === trim($raw)) {
            $proposed['links'] = null;

            return $proposed;
        }

        try {
            $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
            OutboundLinks::assertValid($decoded);
        } catch (\JsonException|\InvalidArgumentException) {
            /* Do not echo validator internals to a hand-crafted POST. */
            $this->reject('contribute.error.invalid_links', 'links');
        }

        $proposed['links'] = $decoded;
        $this->checkLinks($decoded);

        return $proposed;
    }

    /**
     * Submit-side Safe Browsing: fails open, never blocks. Verdicts live in
     * `link_verdict`, not in the `links` attribute.
     */
    private function checkLinks(mixed $links): void
    {
        $urls = SafeBrowsing::urlsIn($links);
        if ([] === $urls) {
            return;
        }

        try {
            $this->linkVerdicts->record($this->safeBrowsing->check($urls));
        } catch (\Throwable) {
            // Fail-open: a background check must not cost the rider the save.
        }
    }

    private function reject(string $message, string $field): never
    {
        throw new ValidationFailedException($message, new ConstraintViolationList([new ConstraintViolation($message, $message, [], $message, $field, null)]));
    }

    /** @param array<string, mixed> $payload */
    private static function hasMedia(array $payload): bool
    {
        return '' !== trim((string) ($payload['mediaIds'] ?? ''));
    }

    /**
     * True when the pin moved more than ~1 m (wizard reposts the item's coords).
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

    /** Text point for was/now; five decimals is about a metre. */
    private static function formatPoint(float $lat, float $lng): string
    {
        return \sprintf('%.5f, %.5f', $lat, $lng);
    }

    /** Collapse '' / null / [] to null; leave every other value untouched. */
    private static function normalizeEmpty(mixed $v): mixed
    {
        return (null === $v || '' === $v || [] === $v) ? null : $v;
    }

    /**
     * Canonical array compare: key-sorted, with `manual` defaulted, so a round-trip marker is not a phantom change.
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
            // Absent `manual` means not placed by hand.
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
     * First coordinate of any GeoJSON geometry, as [lng, lat].
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
     * Measure the climb from its drawn line (docs/specs/climb-elevation.md §4).
     *
     * @param array<string, mixed>      $attrs
     * @param array<string, mixed>|null $current the item's present attributes, when editing
     *
     * @return array<string, mixed>
     */
    private function deriveClimbProfile(array $attrs, ?array $current = null): array
    {
        /* `avg` is a transport key, never an attribute. */
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

        /* A hand-placed marker keeps its position; only the number is re-read (docs/specs/climb-elevation.md §5). */
        $steep = $attrs['steep'] ?? ($current['steep'] ?? null);
        $manualAt = (\is_array($steep) && true === ($steep['manual'] ?? false) && isset($steep['at'][0], $steep['at'][1]))
            ? [(float) $steep['at'][0], (float) $steep['at'][1]]
            : null;

        $p = $this->profiler->profile($coords, $manualAt);
        if (null === $p) {
            // No elevation, no numbers — never a guess (docs/specs/climb-elevation.md §2d).
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
        // Stored headline drifts on redraw; the drawer composes it (docs/specs/climb-elevation.md §4).
        unset($attrs['headline']);

        return $attrs;
    }
}
