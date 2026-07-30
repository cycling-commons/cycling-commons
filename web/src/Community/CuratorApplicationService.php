<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Community;

use App\Community\Entity\CuratorApplication;
use App\Entity\User;
use App\Messaging\MessageService;
use App\Messaging\UserMessageKind;
use App\Moderation\Entity\ModeratorArea;
use App\Service\AdminActionLogger;
use App\Service\UserAdminService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * 2026-07-29-country-requests-and-curator-signup-design.md §5.2, §8, §9.
 *
 * @api Autowired by the DI container; consumed by JoinCountryController and
 *      Admin\DashboardController::curatorApplications(); covered directly by
 *      CuratorApplicationTest and CuratorApplicationReviewTest.
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
     * @throws CuratorApplicationException when the country has no regions, a
     *                                     pending application already exists
     *                                     (sequentially or lost to a race —
     *                                     §8's `uniq_curator_application_pending`
     *                                     is the invariant, this check is
     *                                     just the friendly error before
     *                                     it), or the OSM handle is too long
     *                                     for the column
     * @throws InvalidNoteException        when the about text fails §7 hardening
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
            throw new CuratorApplicationException('not_onboarded', sprintf('%s has no regions yet, so there is nothing to curate.', $cc));
        }

        if ($this->hasPending((int) $user->getId(), $cc)) {
            throw new CuratorApplicationException('already_pending', 'You already have an application pending for this country.');
        }

        $app = new CuratorApplication((int) $user->getId(), $cc);
        $app->setAbout($this->notes->clean($about, PublicNoteFilter::MAX_ABOUT));

        if (null !== $requestedRegionId && $this->regionBelongsToCountry($requestedRegionId, $cc)) {
            $app->setRequestedRegionId($requestedRegionId);
        }

        if (null !== $osmUsername && '' !== trim($osmUsername)) {
            $trimmedHandle = trim($osmUsername);
            if (mb_strlen($trimmedHandle) > 64) {
                // osm_username is varchar(64); the form's maxlength="64" is
                // client-side only, so a crafted or hand-edited POST must be
                // rejected here rather than reaching flush() and 500ing on a
                // column-width violation.
                throw new CuratorApplicationException('osm_handle_too_long', 'That OpenStreetMap username is too long.');
            }
            $app->setOsmUsername($trimmedHandle);
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
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // hasPending() above is read-then-write: two concurrent submits
            // for the same person + country can both pass that check and
            // race to insert. uniq_curator_application_pending (the partial
            // unique index from Version20260729212853) is the real guard;
            // losing the race here means the same thing hasPending() would
            // have reported had it run a moment later, so it gets the same
            // error rather than a raw 500.
            throw new CuratorApplicationException('already_pending', 'You already have an application pending for this country.');
        }

        return $app;
    }

    /**
     * Approval SCOPES rather than promotes: a Dutch applicant curates the
     * Netherlands. Global curator stays something granted deliberately, not a
     * side effect of a form (§9).
     *
     * One transaction end to end. `grantCurator()` opens its own nested
     * `wrapInTransaction` (DBAL 4 nests by ref-count rather than starting a
     * second real transaction — the same pattern
     * `UserAdminService::setModeratorAreas()` relies on), so if persisting
     * the `ModeratorArea` below hits
     * `uniq_moderator_area(user_id, region_id, country_code)` — a second
     * approval racing this one, or a stale retry — the role grant rolls back
     * with it. Without this boundary a conflicting insert would leave the
     * applicant holding ROLE_CURATOR with the application stuck Pending
     * forever, and every retry re-hitting the same constraint.
     *
     * @throws CuratorApplicationException if the application was not Pending,
     *                                     the applicant's account no longer
     *                                     exists, the requested region was
     *                                     deleted after submission (no FK
     *                                     backs `requested_region_id`, so
     *                                     this would otherwise insert a
     *                                     dangling id into `moderator_area`
     *                                     instead of failing loudly — worse
     *                                     than the FK-500 the sibling table
     *                                     would raise), or the applicant is
     *                                     already a GLOBAL curator (zero
     *                                     `moderator_area` rows) and
     *                                     inserting the requested scope
     *                                     would narrow them without a human
     *                                     deciding that on purpose
     */
    public function approve(CuratorApplication $app, User $actor, ?string $note): void
    {
        $this->assertPending($app);

        $applicant = $this->em->getRepository(User::class)->find($app->getUserId());
        if (null === $applicant) {
            throw new CuratorApplicationException('applicant_gone', 'The applicant no longer exists.');
        }

        // requested_region_id carries no FK (unlike moderator_area.region_id,
        // which does — ON DELETE CASCADE). A region deleted after submission
        // would otherwise either FK-500 below or, if that guard ever moved,
        // insert a dangling id silently. Caught here, before the transaction
        // opens, so a stale application never gets partway approved.
        if (null !== $app->getRequestedRegionId()
            && false === $this->db->fetchOne('SELECT 1 FROM region WHERE id = ?', [$app->getRequestedRegionId()])
        ) {
            throw new CuratorApplicationException('region_gone', 'The requested region no longer exists.');
        }

        // §9: approval SCOPES rather than promotes. Someone already holding
        // ROLE_CURATOR with zero moderator_area rows is GLOBAL by the same
        // convention ModeratorArea's own docblock states ("no rows =
        // global") — inserting the newly requested (necessarily narrower)
        // area for them would silently demote a global curator to a
        // country/region one. That is a real decision, not a side effect of
        // approving an unrelated application.
        if ($this->users->hasRole($applicant, 'ROLE_CURATOR')
            && 0 === (int) $this->db->fetchOne('SELECT COUNT(*) FROM moderator_area WHERE user_id = ?', [$applicant->getId()])
        ) {
            throw new CuratorApplicationException('already_global_curator', 'This applicant is already a global curator; approving would narrow their scope to this request. Decide manually.');
        }

        $this->em->wrapInTransaction(function () use ($app, $actor, $note, $applicant): void {
            $this->users->grantCurator($applicant, $actor);

            $this->em->persist(new ModeratorArea(
                $app->getUserId(),
                $app->getRequestedRegionId(),
                null === $app->getRequestedRegionId() ? $app->getCountryCode() : null,
            ));
            // Flushed here, inside the still-open transaction, so a unique-
            // constraint violation surfaces (and rolls back the role grant
            // above with it) rather than being deferred to the automatic
            // flush-on-commit at the end of this closure.
            $this->em->flush();

            $app->decide(CuratorApplicationStatus::Approved, (int) $actor->getId(), $note);

            $this->audit->log($actor, 'curator_application.approve', $applicant, $note);
            $this->notify($app, 'join.message.approved');
        });
    }

    /** @throws CuratorApplicationException if the application was not Pending */
    public function decline(CuratorApplication $app, User $actor, ?string $note): void
    {
        $this->assertPending($app);

        $applicant = $this->em->getRepository(User::class)->find($app->getUserId());

        $this->em->wrapInTransaction(function () use ($app, $actor, $note, $applicant): void {
            $app->decide(CuratorApplicationStatus::Declined, (int) $actor->getId(), $note);

            $this->audit->log($actor, 'curator_application.decline', $applicant, $note);
            $this->notify($app, 'join.message.declined');
        });
    }

    /**
     * Hard guard against re-deciding, regardless of caller: the controller
     * checks status before dispatching too, but this makes the service safe
     * on its own (§9 — re-approving/re-declining must never re-run the
     * side effects: a second role grant, a duplicate moderator_area row, a
     * second notification).
     */
    private function assertPending(CuratorApplication $app): void
    {
        if (CuratorApplicationStatus::Pending !== $app->getStatus()) {
            throw new CuratorApplicationException('already_decided', 'already decided');
        }
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
