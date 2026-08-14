<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Community;

use App\Catalog\OperationalRegions;
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
 * moderation-and-contribution.md.
 *
 * @api Autowired by the DI container; consumed by JoinCountryController and
 *      Admin\DashboardController::curatorApplications(); covered directly by
 *      CuratorApplicationTest and CuratorApplicationReviewTest.
 */
final class CuratorApplicationService
{
    /**
     * Applications per review page. Smaller than the desks' 25 on purpose:
     * each row carries a person's motivation text, their OSM standing and
     * their existing scope, and the reviewer reads all of it.
     */
    public const int PER_PAGE = 15;

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
     *                                     it), the OSM handle is too long
     *                                     for the column, or the social link
     *                                     is not a plausible http(s) URL
     * @throws InvalidNoteException        when the about text fails §7 hardening
     */
    public function submit(
        User $user,
        string $countryCode,
        ?int $requestedRegionId,
        ?string $osmUsername,
        string $about,
        ?string $socialUrl = null,
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
        // Validated before the OSM block so a bad link never costs a live
        // verify round-trip.
        $app->setSocialUrl(null === $socialUrl ? null : $this->normalizeSocialUrl($socialUrl));

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

        /* Acknowledge the sending, not just the deciding (owner-reported
           2026-08-14: "I do not see any mail about my successful application").

           Approve and decline have always notified; submitting told the rider
           nothing beyond a flash they lose on the next click. That is the wrong
           silence: a volunteer has just handed over their name and their
           reasons, and review is a human step with no promised time on it, so
           the gap between sending and hearing back is exactly the stretch where
           a person wonders whether it arrived at all.

           Same rails as the decisions, so it is one record and one wording: a
           dashboard message row, which MessageMailer then delivers to their
           inbox with a link back to it (moderation-and-contribution.md §7.8). */
        $this->notify($app, 'join.message.received', UserMessageKind::CuratorApplicationReceived);
        // Flushed explicitly. The decision paths get this for free from their
        // wrapInTransaction (which flushes on commit); here the application's
        // own flush has already happened above, so without this the message is
        // persisted and never written, the row never reaches the dashboard and
        // MessageMailer has nothing to deliver.
        $this->em->flush();

        return $app;
    }

    /**
     * The optional "where can we find you online" link. People paste
     * scheme-less handles ("instagram.com/rider"), so a missing scheme gets
     * https:// prefixed before validating; any explicit non-http(s) scheme
     * (javascript:, ftp:) is rejected rather than rewritten — the reviewer
     * page renders this as a clickable href, so the scheme allow-list is the
     * XSS boundary, not a nicety. Returns null for a blank field.
     *
     * @throws CuratorApplicationException social_url_invalid
     */
    private function normalizeSocialUrl(string $raw): ?string
    {
        $url = trim($raw);
        if ('' === $url) {
            return null;
        }
        if (null === parse_url($url, PHP_URL_SCHEME)) {
            $url = 'https://'.$url;
        }
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (mb_strlen($url) > 255
            || !\is_string($scheme)
            || !\in_array(strtolower($scheme), ['http', 'https'], true)
            || false === filter_var($url, FILTER_VALIDATE_URL)) {
            throw new CuratorApplicationException('social_url_invalid', 'That link does not look like a valid web address.');
        }

        return $url;
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
        // opens, so a stale application never gets partway approved. The same
        // check also catches a region that is still present but has been
        // demoted to infrastructure since submission
        // (map-and-search.md §4.5 — a country onboarding
        // a deeper level demotes its previous operating level the moment the
        // finer rows land): granting that scope would be a `moderator_area`
        // row for a region no public or moderation surface ever shows.
        if (null !== $app->getRequestedRegionId()
            && false === $this->db->fetchOne(
                'SELECT 1 FROM region WHERE id = ? AND '.OperationalRegions::predicate(),
                [$app->getRequestedRegionId()],
            )
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
    public function pending(int $page = 1, int $perPage = self::PER_PAGE): array
    {
        return $this->em->getRepository(CuratorApplication::class)->findBy(
            ['status' => CuratorApplicationStatus::Pending],
            ['createdAt' => 'ASC'],
            max(1, $perPage),
            max(0, (max(1, $page) - 1) * max(1, $perPage)),
        );
    }

    /** How many applications are waiting, for the pager. */
    public function pendingCount(): int
    {
        return $this->em->getRepository(CuratorApplication::class)
            ->count(['status' => CuratorApplicationStatus::Pending]);
    }

    /**
     * Every application, newest first — the admin desk's list.
     *
     * The desk used to read `pending()`, so a decided application simply
     * vanished from it and there was nowhere to see what had been answered or
     * how (owner 2026-08-14: "clearly add their status Open, accepted or
     * declined and order by latest first"). Newest first because the desk is
     * worked from the top: the oldest-first order that suits a QUEUE is the
     * wrong one for a record.
     *
     * `pending()` keeps its own meaning for anything that genuinely wants the
     * queue, and `pendingCount()` still drives the sidebar's waiting count.
     *
     * @return list<CuratorApplication>
     */
    public function recent(int $page = 1, int $perPage = self::PER_PAGE): array
    {
        return $this->em->getRepository(CuratorApplication::class)->findBy(
            [],
            ['createdAt' => 'DESC', 'id' => 'DESC'],
            max(1, $perPage),
            max(0, (max(1, $page) - 1) * max(1, $perPage)),
        );
    }

    /** How many applications there are in total, for the desk's pager. */
    public function totalCount(): int
    {
        return $this->em->getRepository(CuratorApplication::class)->count([]);
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

    private function notify(CuratorApplication $app, string $bodyKey, UserMessageKind $kind = UserMessageKind::CuratorMessage): void
    {
        $this->messages->sendSystem(
            $app->getUserId(),
            $kind,
            // 'curator_application' does not fit user_message.channel's
            // varchar(12); this is the abbreviation that does.
            'curator_app',
            (int) $app->getId(),
            $app->getCountryCode(),
            $bodyKey,
            ['%scope%' => $this->scopeLabel($app)],
            $app->getDecisionNote(),
        );
    }

    /**
     * What the applicant actually asked to look after, in words.
     *
     * The message used to interpolate `$app->getCountryCode()`, so a rider who
     * volunteered for North Holland was told their application "to curate NL"
     * had arrived (owner-reported 2026-08-14): the wrong scope AND a database
     * code rather than a place. The requested region wins when there is one,
     * because that is what they asked for; the country's name is the answer
     * when they applied country-wide, and the bare code survives only as the
     * last resort for a country row that has gone missing.
     */
    private function scopeLabel(CuratorApplication $app): string
    {
        $regionId = $app->getRequestedRegionId();
        if (null !== $regionId) {
            $name = $this->db->fetchOne('SELECT name FROM region WHERE id = ?', [$regionId]);
            if (false !== $name) {
                return (string) $name;
            }
        }

        $name = $this->db->fetchOne('SELECT name FROM world_country WHERE iso2 = ?', [$app->getCountryCode()]);

        return false !== $name ? (string) $name : $app->getCountryCode();
    }

    private function countryIsOnboarded(string $cc): bool
    {
        return false !== $this->db->fetchOne('SELECT 1 FROM region WHERE country_code = ? LIMIT 1', [$cc]);
    }

    /**
     * The infrastructure-only L2 country outline
     * must not be assignable
     * as a curator's requested scope any more than a region from the wrong
     * country is — a crafted or hand-edited POST could otherwise request it.
     */
    private function regionBelongsToCountry(int $regionId, string $cc): bool
    {
        return false !== $this->db->fetchOne(
            'SELECT 1 FROM region WHERE id = ? AND country_code = ? AND '.OperationalRegions::predicate().' LIMIT 1',
            [$regionId, $cc],
        );
    }

    private function hasPending(int $userId, string $cc): bool
    {
        return null !== $this->pendingApplication($userId, $cc);
    }

    /**
     * The rider's own pending application for this country, if any.
     *
     * Public because the join page asks it before rendering: somebody who has
     * already applied should read what they sent and when, not be handed the
     * same empty form again (owner 2026-08-14). It answers the same question
     * hasPending() does, and returns the row so the page can say which scope
     * they asked for.
     *
     * @return array{id: int, requested_region_id: ?int, created_at: string}|null
     */
    public function pendingApplication(int $userId, string $cc): ?array
    {
        $row = $this->db->fetchAssociative(
            "SELECT id, requested_region_id, created_at
               FROM curator_application
              WHERE user_id = ? AND country_code = ? AND status = 'pending'
              LIMIT 1",
            [$userId, $cc],
        );

        if (false === $row) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'requested_region_id' => null === $row['requested_region_id'] ? null : (int) $row['requested_region_id'],
            'created_at' => (string) $row['created_at'],
        ];
    }
}
