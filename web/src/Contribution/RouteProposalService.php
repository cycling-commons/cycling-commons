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
     * @param array<string, mixed> $meta form data, as for {@see propose()}
     *
     * @see docs/specs/route-domain.md §4.6
     *
     * @throws NotTheSubmitterException     the route is not this rider's proposal
     * @throws AlreadyDecidedException      a curator has decided the proposal
     * @throws TooManyRequestsHttpException over the daily edit limit
     * @throws \InvalidArgumentException    validation failure (message = translation key)
     */
    public function revise(int $routeId, ?string $gpxContent, array $meta, User $user): RecommendedRoute
    {
        $name = self::nameOf($meta);
        if (!$this->routeReviseLimiter->create('user-'.(string) $user->getId())->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(null, 'contribute.error.rate_limited');
        }
        $track = null === $gpxContent ? null : $this->track($gpxContent);

        return $this->em->wrapInTransaction(function () use ($routeId, $name, $track, $meta, $user): RecommendedRoute {
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

            $attributes = self::withMetadata($route->getAttributes(), $meta);
            if (null !== $track) {
                unset($attributes['surfaces']);
                if (null !== $track['surfaces']) {
                    $attributes['surfaces'] = $track['surfaces'];
                }
                $route->setGeom($track['geom'])
                    ->setDistanceM($track['distanceM'])
                    ->setAscentM($track['ascentM'])
                    ->setRegionId($track['regionId']);
            }
            $route->setName($name)->setAttributes($attributes);
            try {
                $this->claims->claimForRoute($meta['mediaIds'] ?? null, $user, $route, null, $meta['mediaAlts'] ?? null);
            } catch (\InvalidArgumentException) {
                throw new \InvalidArgumentException('contribute.error.media_invalid');
            }

            return $route;
        });
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
