<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Community;

use App\Community\Entity\CuratorApplication;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * 2026-07-29-country-requests-and-curator-signup-design.md §5.2, §8, §9.
 */
final class CuratorApplicationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
        private readonly PublicNoteFilter $notes,
        private readonly OsmUserVerifier $osm,
    ) {
    }

    /**
     * @throws \DomainException     when the country has no regions, or a pending application already exists
     * @throws InvalidNoteException when the about text fails §7 hardening
     */
    public function submit(
        User $user,
        string $countryCode,
        ?int $requestedRegionId,
        ?string $osmUsername,
        string $about,
    ): CuratorApplication {
        $cc = strtoupper(trim($countryCode));

        // §4: no region means no evidence path and nothing to scope to.
        if (!$this->countryIsOnboarded($cc)) {
            throw new \DomainException(sprintf('%s has no regions yet, so there is nothing to curate.', $cc));
        }

        if ($this->hasPending((int) $user->getId(), $cc)) {
            throw new \DomainException('You already have an application pending for this country.');
        }

        $app = new CuratorApplication((int) $user->getId(), $cc);
        $app->setAbout($this->notes->clean($about, PublicNoteFilter::MAX_ABOUT));

        if (null !== $requestedRegionId && $this->regionBelongsToCountry($requestedRegionId, $cc)) {
            $app->setRequestedRegionId($requestedRegionId);
        }

        if (null !== $osmUsername && '' !== trim($osmUsername)) {
            $app->setOsmUsername(trim($osmUsername));
            $result = $this->osm->verify($osmUsername);
            if ($result->reachable) {
                // We checked, at this time — regardless of whether the handle
                // turned out to exist. A 404 is real evidence, not an absence
                // of evidence, and must never render the same as "unchecked".
                $app->setOsmVerifiedAt(new \DateTimeImmutable());
                $app->setOsmExists($result->exists);
                if ($result->exists) {
                    // A changeset count for a handle that doesn't exist is meaningless.
                    $app->setOsmChangesetCount($result->changesets);
                }
            }
            // Unreachable: verifiedAt/exists/changesetCount all stay null —
            // "OSM was down" must never be shown to a reviewer as "not found".
        }

        $this->em->persist($app);
        $this->em->flush();

        return $app;
    }

    private function countryIsOnboarded(string $cc): bool
    {
        return false !== $this->db->fetchOne('SELECT 1 FROM region WHERE country_code = ? LIMIT 1', [$cc]);
    }

    private function regionBelongsToCountry(int $regionId, string $cc): bool
    {
        return false !== $this->db->fetchOne(
            'SELECT 1 FROM region WHERE id = ? AND country_code = ? LIMIT 1',
            [$regionId, $cc],
        );
    }

    private function hasPending(int $userId, string $cc): bool
    {
        return false !== $this->db->fetchOne(
            "SELECT 1 FROM curator_application WHERE user_id = ? AND country_code = ? AND status = 'pending' LIMIT 1",
            [$userId, $cc],
        );
    }
}
