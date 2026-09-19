<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Account\StaleNearby;
use App\Catalog\Entity\Submission;
use App\Catalog\ItemState;
use App\Catalog\SubmissionStatus;
use App\Entity\User;
use App\Messaging\MessageService;
use App\Moderation\RetentionService;
use App\Routing\LocalePrefix;
use App\Support\SupportRepository;
use App\Translation\ProposalService;
use App\Translation\TranslationProposalStatus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The rider dashboard: what waits for the rider first, then their own open
 * work, their last contributions and the places near them worth a check.
 *
 * Read-only. It marks nothing read: the Messages badge still counts until the
 * rider opens their messages.
 *
 * @see docs/specs/account-and-auth.md §8
 *
 * @api
 */
#[Route(LocalePrefix::PATHS)]
#[IsGranted('ROLE_USER')]
final class DashboardController extends AbstractController
{
    /** Contributions shown on the dashboard. */
    public const int RECENT = 5;

    /** Stale places near the rider shown on the dashboard. */
    public const int NEARBY = 5;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
        private readonly MessageService $messages,
        private readonly ProposalService $proposals,
        private readonly SupportRepository $support,
        private readonly RetentionService $retention,
        private readonly StaleNearby $staleNearby,
    ) {
    }

    #[Route('/account', name: 'dashboard', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $userId = (int) $user->getId();

        /** @var array<string, int> $submissionCounts status => count */
        $submissionCounts = $this->db->fetchAllKeyValue(
            'SELECT status, COUNT(*) FROM submission
              WHERE user_id = :uid AND status IN (:pending, :needsInfo)
              GROUP BY status',
            ['uid' => $userId, 'pending' => SubmissionStatus::Pending->value, 'needsInfo' => SubmissionStatus::NeedsInfo->value],
        );
        $routeCount = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM recommended_route WHERE proposed_by = :uid AND state = :submitted',
            ['uid' => $userId, 'submitted' => ItemState::Submitted->value],
        );

        // A curator asked something, or somebody wrote: the rider owes the
        // next move, so these stand apart from work that waits on a curator.
        $waiting = [
            ['key' => 'questions', 'route' => 'profile', 'params' => [], 'label' => 'rider_dashboard.tile.questions', 'note' => 'rider_dashboard.note.contributions', 'count' => (int) ($submissionCounts[SubmissionStatus::NeedsInfo->value] ?? 0)],
            ['key' => 'translation_questions', 'route' => 'translate_mine', 'params' => ['status' => TranslationProposalStatus::NeedsInfo->value], 'label' => 'rider_dashboard.tile.questions', 'note' => 'rider_dashboard.note.translations', 'count' => $this->proposals->latestCountFor($userId, TranslationProposalStatus::NeedsInfo)],
            ['key' => 'messages', 'route' => 'messages', 'params' => [], 'label' => 'nav.messages', 'note' => 'rider_dashboard.note.unread', 'count' => $this->messages->unreadCount($userId)],
        ];

        $work = [
            ['key' => 'contributions', 'route' => 'profile', 'params' => [], 'label' => 'account.tab_contributions', 'note' => 'rider_dashboard.note.with_curator', 'count' => (int) ($submissionCounts[SubmissionStatus::Pending->value] ?? 0)],
            ['key' => 'routes', 'route' => 'profile', 'params' => [], 'label' => 'nav.routes', 'note' => 'rider_dashboard.note.with_curator', 'count' => $routeCount],
            ['key' => 'translations', 'route' => 'translate_mine', 'params' => ['status' => TranslationProposalStatus::Pending->value], 'label' => 'nav.translations', 'note' => 'rider_dashboard.note.with_curator', 'count' => $this->proposals->latestCountFor($userId, TranslationProposalStatus::Pending)],
            ['key' => 'reports', 'route' => 'my_reports', 'params' => [], 'label' => 'support.mine.title', 'note' => 'rider_dashboard.note.not_fixed', 'count' => $this->support->countOpenBugsByUser($userId)],
        ];

        // The same retention filter as /profile: a rejected or withdrawn
        // submission past the cutoff is gone there, so it is gone here too
        // (moderation-and-contribution.md §8).
        /** @var list<Submission> $recent */
        $recent = $this->em->createQueryBuilder()
            ->select('s')
            ->from(Submission::class, 's')
            ->where('s.userId = :uid')
            ->andWhere('(s.status NOT IN (:swept) OR s.decidedAt IS NULL OR s.decidedAt >= :cutoff)')
            ->setParameter('uid', $userId)
            ->setParameter('swept', [SubmissionStatus::Rejected, SubmissionStatus::Withdrawn])
            ->setParameter('cutoff', $this->retention->cutoff())
            ->orderBy('s.createdAt', 'DESC')
            ->addOrderBy('s.id', 'DESC')
            ->setMaxResults(self::RECENT)
            ->getQuery()
            ->getResult();

        return $this->render('account/dashboard.html.twig', [
            'page_title' => 'meta.rider_dashboard_title',
            'page_description' => 'meta.rider_dashboard_description',
            'nav_active' => '',
            'cc_user' => $user,
            'waiting' => $waiting,
            'waiting_open' => array_sum(array_column($waiting, 'count')),
            'work' => $work,
            'recent' => $recent,
            'has_base' => $user->hasBaseLocation(),
            'stale_nearby' => $this->staleNearby->for($user, self::NEARBY),
        ]);
    }
}
