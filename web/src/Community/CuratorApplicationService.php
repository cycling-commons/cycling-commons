<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Community;

use App\Community\Entity\CuratorApplication;
use App\Entity\User;
use App\Messaging\MessageService;
use App\Messaging\UserMessageKind;
use App\Service\AdminActionLogger;
use App\Service\UserAdminService;
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
        private readonly UserAdminService $users,
        private readonly AdminActionLogger $audit,
        private readonly MessageService $messages,
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

    /**
     * Approval SCOPES rather than promotes: a Dutch applicant curates the
     * Netherlands. Global curator stays something granted deliberately, not a
     * side effect of a form (§9).
     */
    public function approve(CuratorApplication $app, User $actor, ?string $note): void
    {
        $applicant = $this->em->getRepository(User::class)->find($app->getUserId());
        if (null === $applicant) {
            throw new \DomainException('The applicant no longer exists.');
        }

        $this->users->grantCurator($applicant, $actor);

        $this->db->insert('moderator_area', [
            'user_id' => $app->getUserId(),
            'region_id' => $app->getRequestedRegionId(),
            'country_code' => null === $app->getRequestedRegionId() ? $app->getCountryCode() : null,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $app->decide(CuratorApplicationStatus::Approved, (int) $actor->getId(), $note);
        $this->em->flush();

        $this->audit->log($actor, 'curator_application.approve', $applicant, $note);
        $this->notify($app, 'join.message.approved');
        // sendSystem() persists WITHOUT flushing (its callers normally ride a
        // decision transaction's flush-on-commit); nothing else flushes after
        // it here, so this call is the one that actually writes the message.
        $this->em->flush();
    }

    public function decline(CuratorApplication $app, User $actor, ?string $note): void
    {
        $applicant = $this->em->getRepository(User::class)->find($app->getUserId());

        $app->decide(CuratorApplicationStatus::Declined, (int) $actor->getId(), $note);
        $this->em->flush();

        $this->audit->log($actor, 'curator_application.decline', $applicant, $note);
        $this->notify($app, 'join.message.declined');
        $this->em->flush();
    }

    /** @return list<CuratorApplication> */
    public function pending(): array
    {
        return $this->em->getRepository(CuratorApplication::class)
            ->findBy(['status' => CuratorApplicationStatus::Pending], ['createdAt' => 'ASC']);
    }

    /**
     * Evidence read live rather than snapshotted (§8), so the reviewer always
     * sees the applicant's current standing.
     *
     * @return array{total: int, approved: int}
     */
    public function evidenceFor(CuratorApplication $app): array
    {
        $row = $this->db->fetchAssociative(
            "SELECT COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE status = 'approved') AS approved
             FROM submission WHERE user_id = ? AND country_code = ?",
            [$app->getUserId(), $app->getCountryCode()],
        );

        return ['total' => (int) ($row['total'] ?? 0), 'approved' => (int) ($row['approved'] ?? 0)];
    }

    private function notify(CuratorApplication $app, string $bodyKey): void
    {
        $this->messages->sendSystem(
            $app->getUserId(),
            UserMessageKind::CuratorMessage,
            // 'curator_application' does not fit user_message.channel's
            // varchar(12); this is the abbreviation that does.
            'curator_app',
            (int) $app->getId(),
            $app->getCountryCode(),
            $bodyKey,
            ['%country%' => $app->getCountryCode()],
            $app->getDecisionNote(),
        );
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
