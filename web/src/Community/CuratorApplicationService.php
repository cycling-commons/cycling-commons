<?php

// SPDX-License-Identifier: AGPL-3.0-only

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
use App\World\CuratorScopes;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Curator applications: submit, approve (scope, not promote), decline.
 *
 * @see docs/specs/moderation-and-contribution.md §11
 *
 * @api
 */
final class CuratorApplicationService
{
    public const int PER_PAGE = 15;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
        private readonly PublicNoteFilter $notes,
        private readonly OsmUserVerifier $osm,
        private readonly UserAdminService $users,
        private readonly AdminActionLogger $audit,
        private readonly MessageService $messages,
        private readonly CuratorScopes $scopes,
    ) {
    }

    /**
     * @throws CuratorApplicationException pending duplicate, not onboarded, bad OSM handle, or invalid social URL
     * @throws InvalidNoteException        when the about text fails hardening
     */
    public function submit(
        User $user,
        string $countryCode,
        ?int $requestedRegionId,
        ?string $osmUsername,
        string $about,
        ?string $socialUrl = null,
        string $requestedArea = '',
    ): CuratorApplication {
        $cc = strtoupper(trim($countryCode));

        if (!$this->countryIsOnboarded($cc)) {
            throw new CuratorApplicationException('not_onboarded', sprintf('%s has no regions yet, so there is nothing to curate.', $cc));
        }

        if ($this->hasPending((int) $user->getId(), $cc)) {
            throw new CuratorApplicationException('already_pending', 'You already have an application pending for this country.');
        }

        $app = new CuratorApplication((int) $user->getId(), $cc);
        $app->setAbout($this->notes->clean($about, PublicNoteFilter::MAX_ABOUT));
        $app->setSocialUrl(null === $socialUrl ? null : $this->normalizeSocialUrl($socialUrl));

        if (null !== $requestedRegionId && $this->regionBelongsToCountry($requestedRegionId, $cc)) {
            $app->setRequestedRegionId($requestedRegionId);
        }

        // An area with no region row yet, and NOT free text.
        //
        // A curator's scope draws a line on the map, and two lines drawn from
        // whatever somebody typed will sooner or later cross: one applicant
        // asks for a province, another for a town inside it, and two curators
        // hold the same ground with no way to say who decides (owner
        // 2026-09-13). Every country is seeded at one operating level for that
        // reason (the tessellation invariant, map-and-search.md §4.5a), and a
        // scope that arrives by name has to respect it too.
        //
        // So the name must be one the reference data already holds, and what
        // is stored is OUR spelling of it rather than the posted string: a
        // match that then saves the applicant's capitalisation would put two
        // spellings of one place on the desk. A name that matches nothing is
        // refused rather than dropped, because silently widening somebody to
        // the whole country is a scope they did not ask for.
        $area = trim($requestedArea);
        if ('' !== $area && null === $app->getRequestedRegionId()) {
            $known = null;
            foreach ($this->scopes->forCountry($cc) as $candidate) {
                if (mb_strtolower($candidate) === mb_strtolower($area)) {
                    $known = $candidate;
                    break;
                }
            }
            if (null === $known) {
                throw new CuratorApplicationException('area_unknown', sprintf('"%s" is not an area we hold a boundary for in %s.', $area, $cc));
            }
            $app->setRequestedArea($known);
        }

        if (null !== $osmUsername && '' !== trim($osmUsername)) {
            $trimmedHandle = trim($osmUsername);
            if (mb_strlen($trimmedHandle) > 64) {
                throw new CuratorApplicationException('osm_handle_too_long', 'That OpenStreetMap username is too long.');
            }
            $app->setOsmUsername($trimmedHandle);
            $result = $this->osm->verify($osmUsername);
            if ($result->reachable) {
                // Checked now — a 404 is evidence, not "unchecked".
                $app->setOsmVerifiedAt(new \DateTimeImmutable());
                $app->setOsmExists($result->exists);
                if ($result->exists) {
                    $app->setOsmChangesetCount($result->changesets);
                }
            }
        }

        $this->em->persist($app);
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            throw new CuratorApplicationException('already_pending', 'You already have an application pending for this country.');
        }

        $this->notify($app, 'join.message.received', UserMessageKind::CuratorApplicationReceived);
        // Application flush already ran; this writes the acknowledgement message.
        $this->em->flush();

        return $app;
    }

    /**
     * Optional http(s) URL. Scheme-less input gets https://; other schemes are rejected.
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
     * Approval scopes (country/region); it does not grant global curator.
     *
     * @throws CuratorApplicationException if not pending, applicant gone, region gone, or already global
     */
    public function approve(CuratorApplication $app, User $actor, ?string $note): void
    {
        $this->assertPending($app);

        $applicant = $this->em->getRepository(User::class)->find($app->getUserId());
        if (null === $applicant) {
            throw new CuratorApplicationException('applicant_gone', 'The applicant no longer exists.');
        }

        if (null !== $app->getRequestedRegionId()
            && false === $this->db->fetchOne(
                'SELECT 1 FROM region WHERE id = ? AND '.OperationalRegions::predicate(),
                [$app->getRequestedRegionId()],
            )
        ) {
            throw new CuratorApplicationException('region_gone', 'The requested region no longer exists.');
        }

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

    /** @throws CuratorApplicationException if the application was not Pending */
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

    public function pendingCount(): int
    {
        return $this->em->getRepository(CuratorApplication::class)
            ->count(['status' => CuratorApplicationStatus::Pending]);
    }

    /**
     * Every application, newest first.
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

    public function totalCount(): int
    {
        return $this->em->getRepository(CuratorApplication::class)->count([]);
    }

    /**
     * Live submission counts for this applicant + country, not a snapshot.
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
            // varchar(12) channel; 'curator_application' does not fit.
            'curator_app',
            (int) $app->getId(),
            $app->getCountryCode(),
            $bodyKey,
            ['%scope%' => $this->scopeLabel($app)],
            $app->getDecisionNote(),
        );
    }

    /** Requested region name, else country name, else the ISO code. */
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
