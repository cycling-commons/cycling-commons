<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Planner;

use App\Entity\User;
use App\Planner\Entity\PlannedRide;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The rides a rider saved: save, open, list, delete and count, every one for
 * one rider. Another rider's ride is a missing ride here; nothing answers
 * that it exists.
 *
 * A save measures the line where it is stored (`ST_Length` on the
 * geography) and refuses a line over the route domain's cap or a claimed
 * distance more than five percent off it. Ascent and descent are the
 * rider's figures, kept as claimed: the server holds no heights.
 *
 * @see docs/specs/route-planner.md section 6.1 and 8
 *
 * @api
 */
final class PlannedRides
{
    /** Owner decision, route-planner.md section 6.1. */
    public const int MAX_RIDES = 100;

    /** The route domain's cap on one ride, the bound `/map/plan/along` uses too. */
    public const int MAX_LENGTH_M = 400_000;

    /** How far the claimed distance may sit from the measured line. */
    public const float LENGTH_TOLERANCE = 0.05;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
    ) {
    }

    /**
     * Inserts, or updates when the input names a ride of this rider.
     *
     * @throws RideRefused
     */
    public function save(User $rider, RideInput $input): PlannedRide
    {
        if (null !== $input->id) {
            $ride = $this->find($rider, $input->id) ?? throw new RideRefused(RideRefused::NOT_FOUND);
        } else {
            if ($this->count($rider) >= self::MAX_RIDES) {
                throw new RideRefused(RideRefused::TOO_MANY_RIDES);
            }
            $ride = new PlannedRide((int) $rider->getId());
        }

        $line = $input->lineGeoJson();
        $lengthM = $this->lengthM($line);
        if ($lengthM <= 0.0 || $lengthM > self::MAX_LENGTH_M) {
            throw new RideRefused(RideRefused::LENGTH_RANGE);
        }
        if (abs($input->distanceM - $lengthM) > self::LENGTH_TOLERANCE * $lengthM) {
            throw new RideRefused(RideRefused::LENGTH_MISMATCH);
        }

        $ride->fill($input->name, $input->bike, $input->stops, $line, $input->distanceM, $input->ascentM, $input->descentM);
        $this->em->persist($ride);
        $this->em->flush();

        return $ride;
    }

    /** The rider's ride, or null when it is missing or somebody else's. */
    public function find(User $rider, int $id): ?PlannedRide
    {
        return $this->em->getRepository(PlannedRide::class)->findOneBy(['id' => $id, 'userId' => (int) $rider->getId()]);
    }

    /** @return list<PlannedRide> newest updated first */
    public function listFor(User $rider): array
    {
        return $this->em->getRepository(PlannedRide::class)->findBy(
            ['userId' => (int) $rider->getId()],
            ['updatedAt' => 'DESC', 'id' => 'DESC'],
        );
    }

    /** Whether a ride of this rider was there to delete. */
    public function delete(User $rider, int $id): bool
    {
        $ride = $this->find($rider, $id);
        if (null === $ride) {
            return false;
        }
        $this->em->remove($ride);
        $this->em->flush();

        return true;
    }

    public function count(User $rider): int
    {
        return $this->em->getRepository(PlannedRide::class)->count(['userId' => (int) $rider->getId()]);
    }

    /** The line's geodesic length in metres, as the database measures it. */
    private function lengthM(string $lineGeoJson): float
    {
        return (float) $this->db->fetchOne(
            'SELECT ST_Length(ST_SetSRID(ST_GeomFromGeoJSON(:line), 4326)::geography)',
            ['line' => $lineGeoJson],
        );
    }
}
