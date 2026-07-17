<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Contribution;

use App\Catalog\BikeTypeVocabulary;
use App\Catalog\DifficultyVocabulary;
use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\SurfaceProfiler;
use App\Contribution\Gpx\GpxParser;
use App\Contribution\Gpx\TrackProcessor;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Route proposal intake (docs/specs/route-domain.md §4). Deliberately not part of the
 * item Submission pipeline: a proposal is a RecommendedRoute row in state
 * `submitted`, reviewed later in the Routes moderation queue.
 *
 * Processing order (spec §4.2): parse → raw-length guard → privacy trim
 * (content-hash seeded, only the trimmed track is ever persisted) →
 * distance/ascent on the trimmed track → simplify for serving → region.
 *
 * @api Route-proposal intake entry point (docs/specs/route-domain.md §4); consumed by
 *      ProposeRouteController, covered by RouteProposalServiceTest.
 */
final class RouteProposalService
{
    private const int MIN_RAW_M = 2_000;    // spec §4.1
    private const int MAX_RAW_M = 400_000;  // spec §4.1

    /** Attribute keys copied from the metadata form when non-empty. */
    private const array META_KEYS = ['difficulty', 'season', 'dominantSurface', 'note', 'bikeTypes', 'gradientLimited'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GpxParser $parser,
        private readonly TrackProcessor $processor,
        private readonly RegionResolver $regions,
        private readonly SurfaceProfiler $profiler,
        private readonly RateLimiterFactoryInterface $routeProposeLimiter,
    ) {
    }

    /**
     * @param array<string, mixed> $meta form data (rName + META_KEYS)
     *
     * @throws TooManyRequestsHttpException over the daily proposal limit
     * @throws \InvalidArgumentException    validation failure (message = translation key)
     */
    public function propose(string $gpxContent, array $meta, User $user): RecommendedRoute
    {
        if (!$this->routeProposeLimiter->create('user-'.(string) $user->getId())->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(null, 'contribute.error.rate_limited');
        }

        $rName = $meta['rName'] ?? null;
        $name = \is_string($rName) ? trim($rName) : '';
        if ('' === $name) {
            throw new \InvalidArgumentException('propose_route.error.name_required');
        }

        $track = $this->parser->parse($gpxContent);

        $rawM = $this->processor->distanceM($track->points);
        if ($rawM < self::MIN_RAW_M || $rawM > self::MAX_RAW_M) {
            throw new \InvalidArgumentException('propose_route.error.length_range');
        }

        $trimmed = $this->processor->trim($track->points, hash('sha256', $gpxContent));
        $distanceM = (int) round($this->processor->distanceM($trimmed));
        $ascentM = $this->processor->ascentM($trimmed);

        // Serve-resolution geometry; [lat,lng] triples → GeoJSON [lng,lat].
        $coords = array_map(
            static fn (array $p): array => [$p[1], $p[0]],
            $this->processor->simplify($trimmed),
        );
        $geoJson = json_encode(
            ['type' => 'LineString', 'coordinates' => $coords],
            \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION,
        );

        $attributes = [];
        foreach (self::META_KEYS as $key) {
            $value = $meta[$key] ?? null;
            if (null !== $value && '' !== $value && [] !== $value) {
                $attributes[$key] = $value;
            }
        }
        // Canonicalize difficulty to {score,label} (docs/specs/route-domain.md §9) so
        // the form's rider-facing string is never persisted verbatim.
        $canonicalDifficulty = DifficultyVocabulary::canonical($meta['difficulty'] ?? null);
        if (null !== $canonicalDifficulty) {
            $attributes['difficulty'] = $canonicalDifficulty;
        } else {
            unset($attributes['difficulty']);
        }
        // Store bikeTypes as a deduplicated list of valid BikeType values
        // (docs/specs/route-domain.md §9).
        $bikeTypes = BikeTypeVocabulary::normalize($meta['bikeTypes'] ?? null);
        if ([] !== $bikeTypes) {
            $attributes['bikeTypes'] = $bikeTypes;
        } else {
            unset($attributes['bikeTypes']);
        }

        // Derived, never user-supplied: the surfaces the trimmed track actually
        // crosses, measured against the served A-layer segments (honest estimate
        // with disclosed coverage). Absent when nothing mapped is near the route.
        $surfaces = $this->profiler->profile($geoJson);
        if (null !== $surfaces) {
            $attributes['surfaces'] = $surfaces;
        }

        $route = (new RecommendedRoute())
            ->setName($name)
            ->setGeom($geoJson)
            ->setDistanceM($distanceM)
            ->setAscentM($ascentM)
            ->setRegionId($this->regions->resolve($geoJson))
            ->setState(ItemState::Submitted)
            ->setSource(ItemSource::User)
            // Unique per proposal so harvest upserts keyed on (source,
            // source_ref) can never clobber rider proposals (spec §2.1).
            ->setSourceRef('user:'.bin2hex(random_bytes(12)))
            ->setAttributes($attributes)
            ->setProposedBy($user->getId())
            ->setImportedAt(null);

        $this->em->persist($route);
        $this->em->flush();

        return $route;
    }
}
