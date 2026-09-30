<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Contribution;

use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\RouteMetadata;
use App\Catalog\SurfaceProfiler;
use App\Contribution\Gpx\GpxParser;
use App\Contribution\Gpx\TrackProcessor;
use App\Entity\User;
use App\Media\MediaClaimService;
use App\Moderation\AlreadyDecidedException;
use App\Moderation\NotTheSubmitterException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Route proposal intake (docs/specs/route-domain.md §4,
 * docs/specs/edit-items/R-quality-rides.md). A proposal is a RecommendedRoute
 * in state `submitted`, not an item Submission.
 *
 * @api
 */
final class RouteProposalService
{
    /* Public so the error message can quote them in the reader's units. */
    public const int MIN_RAW_M = 2_000;     // spec §4.1
    public const int MAX_RAW_M = 400_000;   // spec §4.1

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GpxParser $parser,
        private readonly TrackProcessor $processor,
        private readonly RegionResolver $regions,
        private readonly SurfaceProfiler $profiler,
        private readonly RateLimiterFactoryInterface $routeProposeLimiter,
        private readonly RateLimiterFactoryInterface $routeReviseLimiter,
        private readonly MediaClaimService $claims,
    ) {
    }

    /**
     * @param array<string, mixed> $meta form data ({@see RouteMetadata::NAME_FIELD} +
     *                                   {@see RouteMetadata::ATTRIBUTE_FIELDS}, and the photos' mediaIds/mediaAlts)
     *
     * @throws TooManyRequestsHttpException over the daily proposal limit
     * @throws \InvalidArgumentException    validation failure (message = translation key)
     */
    public function propose(string $gpxContent, array $meta, User $user): RecommendedRoute
    {
        if (!$this->routeProposeLimiter->create('user-'.(string) $user->getId())->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(null, 'contribute.error.rate_limited');
        }

        $name = self::nameOf($meta);
        $track = $this->track($gpxContent);

        // One intake gate for both forms: canonical shapes, vocabularies
        // enforced, and a field that carries nothing storing no key at all.
        $attributes = self::withMetadata([], $meta);
        if (null !== $track['surfaces']) {
            $attributes['surfaces'] = $track['surfaces'];
        }

        $route = (new RecommendedRoute())
            ->setName($name)
            ->setGeom($track['geom'])
            ->setDistanceM($track['distanceM'])
            ->setAscentM($track['ascentM'])
            ->setRegionId($track['regionId'])
            ->setState(ItemState::Submitted)
            ->setSource(ItemSource::User)
            // Unique per proposal so harvest upserts cannot clobber rider rows (docs/specs/route-domain.md §2.1).
            ->setSourceRef('user:'.bin2hex(random_bytes(12)))
            ->setAttributes($attributes)
            ->setProposedBy($user->getId())
            ->setImportedAt(null);

        // Photos ride the proposal's own transaction and its decision (photo-uploads.md §5i).
        return $this->em->wrapInTransaction(function () use ($route, $meta, $user): RecommendedRoute {
            $this->em->persist($route);
            $this->em->flush();
            try {
                $this->claims->claimForRoute($meta['mediaIds'] ?? null, $user, $route, null, $meta['mediaAlts'] ?? null);
            } catch (\InvalidArgumentException) {
                throw new \InvalidArgumentException('contribute.error.media_invalid');
            }
            $this->em->flush();

            return $route;
        });
    }

    /**
     * The proposer's own edit of a route still waiting for review: the same
     * fields as the proposal, and optionally a new GPX that replaces the track
     * and everything derived from it (length, climb, region, surfaces). The
     * row is locked and its state read again inside the transaction, so an
     * edit racing a curator's decision ends in AlreadyDecidedException, never
     * in a change to a decided route. Photos already sent stay; new ones in
     * `mediaIds` are claimed by the proposal in the same transaction, within
     * the same cap of 6, and decided with it (photo-uploads.md §5i).
     *
     * A field is written only when the proposer changed it from what their
     * form showed (`$meta['shown']`, the form's values when it was rendered;
     * without it, the route's values now), so a save never writes back a
     * value the proposer merely left alone. A field a curator has edited on
     * the Routes desk ({@see curatorHeld()}) is never written: the proposer's
     * change to it is dropped and named in the result. Any change marks the
     * route revised, which the desk card shows.
     *
     * @param array<string, mixed> $meta form data, as for {@see propose()}, plus `shown`
     *
     * @see docs/specs/route-domain.md §4.6
     *
     * @throws NotTheSubmitterException     the route is not this rider's proposal
     * @throws AlreadyDecidedException      a curator has decided the proposal
     * @throws TooManyRequestsHttpException over the daily edit limit
     * @throws \InvalidArgumentException    validation failure (message = translation key)
     */
    public function revise(int $routeId, ?string $gpxContent, array $meta, User $user): RouteRevision
    {
        $name = self::nameOf($meta);
        if (!$this->routeReviseLimiter->create('user-'.(string) $user->getId())->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(null, 'contribute.error.rate_limited');
        }
        $track = null === $gpxContent ? null : $this->track($gpxContent);

        return $this->em->wrapInTransaction(function () use ($routeId, $name, $track, $meta, $user): RouteRevision {
            $route = $this->em->find(RecommendedRoute::class, $routeId, LockMode::PESSIMISTIC_WRITE);
            if (null === $route || null === $route->getProposedBy() || $route->getProposedBy() !== $user->getId()) {
                throw new NotTheSubmitterException('Not this rider\'s route proposal.');
            }
            // The lock above makes this read final: a curator's decision
            // either landed before it or waits for this edit to commit.
            $this->em->refresh($route);
            if (ItemState::Submitted !== $route->getState()) {
                throw new AlreadyDecidedException('The route proposal has been decided.');
            }

            $held = $this->curatorHeld($route);
            $shown = self::shownOf($meta);
            $kept = [];
            $changed = false;

            $shownName = \is_string($shown[RouteMetadata::NAME_FIELD] ?? null) ? trim($shown[RouteMetadata::NAME_FIELD]) : $route->getName();
            if ($name !== $shownName && $name !== $route->getName()) {
                if (\in_array(RouteMetadata::NAME_FIELD, $held, true)) {
                    $kept[] = RouteMetadata::NAME_FIELD;
                } else {
                    $route->setName($name);
                    $changed = true;
                }
            }

            $attributes = $route->getAttributes();
            foreach (RouteMetadata::ATTRIBUTE_FIELDS as $key) {
                $current = RouteMetadata::canonical($key, $attributes[$key] ?? null);
                $mine = RouteMetadata::canonical($key, $meta[$key] ?? null);
                $was = \array_key_exists($key, $shown) ? RouteMetadata::canonical($key, $shown[$key]) : $current;
                if (RouteMetadata::isSame($mine, $was) || RouteMetadata::isSame($mine, $current)) {
                    continue;
                }
                if (\in_array($key, $held, true)) {
                    $kept[] = $key;
                    continue;
                }
                if (null === $mine) {
                    unset($attributes[$key]);
                } else {
                    $attributes[$key] = $mine;
                }
                $changed = true;
            }

            if (null !== $track) {
                unset($attributes['surfaces']);
                if (null !== $track['surfaces']) {
                    $attributes['surfaces'] = $track['surfaces'];
                }
                $route->setGeom($track['geom'])
                    ->setDistanceM($track['distanceM'])
                    ->setAscentM($track['ascentM'])
                    ->setRegionId($track['regionId']);
                $changed = true;
            }
            $route->setAttributes($attributes);

            $photosBefore = $this->claims->routePhotoCount($routeId, null);
            try {
                $this->claims->claimForRoute($meta['mediaIds'] ?? null, $user, $route, null, $meta['mediaAlts'] ?? null);
            } catch (\InvalidArgumentException) {
                throw new \InvalidArgumentException('contribute.error.media_invalid');
            }
            $this->em->flush();
            if ($changed || $this->claims->routePhotoCount($routeId, null) > $photosBefore) {
                $route->markRevised();
            }

            return new RouteRevision($route, $kept);
        });
    }

    /**
     * The fields of a proposal a curator has edited on the Routes desk, in the
     * forms' order: every field with a `route_change_history` row written by
     * anyone but the proposer. While the proposal waits, a curator's edit of a
     * field is the reviewer's word on it, so the proposer's form shows these
     * locked and {@see revise()} never writes them.
     *
     * @return list<string> keys from {@see RouteMetadata::EDITABLE_FIELDS}
     */
    public function curatorHeld(RecommendedRoute $route): array
    {
        /** @var list<string> $fields */
        $fields = $this->em->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT field FROM route_change_history
              WHERE route_id = :route AND changed_by IS DISTINCT FROM :proposer',
            ['route' => (int) $route->getId(), 'proposer' => $route->getProposedBy()],
        );
        // History files a rename under `name`; the forms post it as NAME_FIELD.
        $fields = array_map(static fn (string $f): string => 'name' === $f ? RouteMetadata::NAME_FIELD : $f, $fields);

        return array_values(array_intersect(RouteMetadata::EDITABLE_FIELDS, $fields));
    }

    /**
     * The values the proposer's form showed, keyed as the form posts them, from
     * its `shown` field. Empty when the form carried none, which makes the
     * route's values now the baseline.
     *
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private static function shownOf(array $meta): array
    {
        $raw = $meta['shown'] ?? null;
        if (!\is_string($raw) || '' === $raw) {
            return [];
        }
        try {
            $shown = json_decode($raw, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!\is_array($shown)) {
            return [];
        }

        return array_intersect_key($shown, array_flip(RouteMetadata::EDITABLE_FIELDS));
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @throws \InvalidArgumentException a blank name
     */
    private static function nameOf(array $meta): string
    {
        $rName = $meta[RouteMetadata::NAME_FIELD] ?? null;
        $name = \is_string($rName) ? trim($rName) : '';
        if ('' === $name) {
            throw new \InvalidArgumentException('propose_route.error.name_required');
        }

        return $name;
    }

    /**
     * The registry fields from the form over `$attributes`: each through
     * {@see RouteMetadata::canonical()}, a field that carries nothing leaving
     * no key. Keys outside the registry (surfaces, photos) are kept.
     *
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private static function withMetadata(array $attributes, array $meta): array
    {
        foreach (RouteMetadata::ATTRIBUTE_FIELDS as $key) {
            $value = RouteMetadata::canonical($key, $meta[$key] ?? null);
            if (null === $value) {
                unset($attributes[$key]);
            } else {
                $attributes[$key] = $value;
            }
        }

        return $attributes;
    }

    /**
     * A GPX made into what a route stores: the privacy-trimmed, simplified
     * line as GeoJSON, its length and climb, its region and its derived
     * surfaces (spec §4.2, §4.3).
     *
     * @return array{geom: string, distanceM: int, ascentM: int|null, regionId: int|null, surfaces: array{covered: int, parts: list<array{surface: string, pct: int}>}|null}
     *
     * @throws \InvalidArgumentException an unreadable track or one outside the length range
     */
    private function track(string $gpxContent): array
    {
        $track = $this->parser->parse($gpxContent);

        $rawM = $this->processor->distanceM($track->points);
        if ($rawM < self::MIN_RAW_M || $rawM > self::MAX_RAW_M) {
            throw new \InvalidArgumentException('propose_route.error.length_range');
        }

        $trimmed = $this->processor->trim($track->points, hash('sha256', $gpxContent));

        // Serve-resolution geometry; [lat,lng] triples → GeoJSON [lng,lat].
        $coords = array_map(
            static fn (array $p): array => [$p[1], $p[0]],
            $this->processor->simplify($trimmed),
        );
        $geoJson = json_encode(
            ['type' => 'LineString', 'coordinates' => $coords],
            \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION,
        );

        return [
            'geom' => $geoJson,
            'distanceM' => (int) round($this->processor->distanceM($trimmed)),
            'ascentM' => $this->processor->ascentM($trimmed),
            'regionId' => $this->regions->resolve($geoJson),
            // Derived surfaces from the trimmed track; never user-supplied.
            'surfaces' => $this->profiler->profile($geoJson),
        ];
    }
}
