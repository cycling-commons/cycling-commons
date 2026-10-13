<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Content\ReleaseNotes;
use App\Entity\User;
use App\Roadmap\Entity\RoadmapProposal;
use App\Roadmap\IdeaDesk;
use App\Roadmap\ProposalIntake;
use App\Roadmap\ProposalStatus;
use App\Roadmap\RoadmapMarks;
use App\Roadmap\RoadmapRefused;
use App\Routing\LocalePrefix;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The roadmap desk's proposals: riders' ideas, read and decided by an admin
 * (open for votes, promote to the roadmap, decline).
 *
 * Curators read the proposals and their pictures; only an admin decides.
 * Curators look after the map of their region and know what riders ask for;
 * what the Commons builds next is the project's call. It has the bug
 * desk's shape (a list with status chips, one page per proposal, one decision
 * form, the proposer told through their inbox), so deciding a proposal works
 * the way deciding a bug does.
 *
 * @see docs/specs/roadmap-and-changelog.md §8
 *
 * @api
 */
#[Route(LocalePrefix::PATHS)]
#[IsGranted('ROLE_CURATOR')]
final class ModerateIdeasController extends AbstractController
{
    public const string CSRF_ID = 'roadmap-idea-decide';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
        private readonly IdeaDesk $desk,
        private readonly ProposalIntake $intake,
        private readonly RoadmapMarks $marks,
    ) {
    }

    #[Route('/moderate/ideas', name: 'moderate_ideas', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filter = $request->query->getString('status', ProposalStatus::Pending->value);
        $status = ProposalStatus::tryFrom($filter);
        if (null === $status) {
            $filter = 'all';
        }

        $ideas = $this->em->getRepository(RoadmapProposal::class)->findBy(
            null === $status ? [] : ['status' => $status],
            ['createdAt' => 'DESC', 'id' => 'DESC'],
            200,
        );

        /** @var array<string, int|string> $counts */
        $counts = $this->db->fetchAllKeyValue('SELECT status, COUNT(*) FROM roadmap_proposal GROUP BY status');

        return $this->render('moderate/ideas.html.twig', [
            'page_title' => 'roadmap.desk.title',
            'ideas' => $ideas,
            'filter_status' => $filter,
            'statuses' => ProposalStatus::all(),
            'counts' => array_map(intval(...), $counts),
            'count_all' => array_sum(array_map(intval(...), $counts)),
            'votes' => $this->marks->voteCounts(array_map(static fn (RoadmapProposal $p): string => $p->getRef(), $ideas)),
            'pictures' => $this->pictures($ideas),
        ]);
    }

    #[Route('/moderate/ideas/{id}', name: 'moderate_ideas_detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function detail(int $id): Response
    {
        return $this->renderDetail($this->find($id));
    }

    #[Route('/moderate/ideas/{id}/decide', name: 'moderate_ideas_decide', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function decide(int $id, Request $request): Response
    {
        $proposal = $this->find($id);
        $admin = $this->getUser();
        \assert($admin instanceof User);
        $form = $request->request->all();
        $token = $form['_token'] ?? null;
        if (!$this->isCsrfTokenValid(self::CSRF_ID, \is_string($token) ? $token : '')) {
            $this->addFlash('notice', 'flash.invalid_token');

            return $this->redirectToRoute('moderate_ideas_detail', ['id' => $id], Response::HTTP_SEE_OTHER);
        }

        $field = static fn (string $name): ?string => \is_string($form[$name] ?? null) ? $form[$name] : null;
        try {
            $this->desk->decide(
                $proposal,
                (string) $field('action'),
                $field('group'),
                $field('public_title'),
                $field('public_body'),
                $field('note'),
                (int) $admin->getId(),
                // The box only exists when there is a picture the rider allowed.
                isset($form['picture_offered']) ? '1' === ($form['show_picture'] ?? null) : null,
            );
        } catch (RoadmapRefused $e) {
            return $this->renderDetail($proposal, $e->messageKey(), [
                'action' => $field('action'),
                'group' => $field('group'),
                'public_title' => $field('public_title'),
                'public_body' => $field('public_body'),
                'note' => $field('note'),
                'show_picture' => '1' === ($form['show_picture'] ?? null),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // A promoted proposal goes on to its new item, where the desk can
        // write it in the other languages.
        $item = $proposal->getPromotedItem();
        if (null !== $item) {
            $this->addFlash('success', 'roadmap.desk.promoted');

            return $this->redirectToRoute('moderate_roadmap_item', ['id' => $item->getId()], Response::HTTP_SEE_OTHER);
        }
        $this->addFlash('success', 'roadmap.desk.saved');

        return $this->redirectToRoute('moderate_ideas_detail', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    #[Route('/moderate/ideas/{id}/image', name: 'moderate_ideas_image', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function image(int $id): Response
    {
        return RoadmapProposalController::serveImage($this->intake->imageOf($this->find($id)), $id);
    }

    /** @param array<string, string|bool|null>|null $draft what the admin sent, after a refused save */
    private function renderDetail(RoadmapProposal $proposal, ?string $error = null, ?array $draft = null, int $status = Response::HTTP_OK): Response
    {
        return $this->render('moderate/idea_detail.html.twig', [
            'page_title' => 'roadmap.desk.title',
            'idea' => $proposal,
            'image' => $this->intake->imageOf($proposal),
            'votes' => $this->marks->voteCounts([$proposal->getRef()])[$proposal->getRef()] ?? 0,
            'followers' => \count($this->marks->followers($proposal->getRef())),
            'groups' => ReleaseNotes::STATUSES,
            'pending' => $this->intake->pendingCount(),
            'csrf_id' => self::CSRF_ID,
            'error' => $error,
            'draft' => $draft,
        ], new Response(null, $status));
    }

    /**
     * Which proposals carry a picture, and whether it shows publicly.
     *
     * @param list<RoadmapProposal> $ideas
     *
     * @return array<int, bool>
     */
    private function pictures(array $ideas): array
    {
        $pictures = [];
        foreach ($ideas as $idea) {
            $image = $this->intake->imageOf($idea);
            if (null !== $image) {
                $pictures[(int) $idea->getId()] = $image->isPublic();
            }
        }

        return $pictures;
    }

    private function find(int $id): RoadmapProposal
    {
        $proposal = $this->em->find(RoadmapProposal::class, $id);
        if (!$proposal instanceof RoadmapProposal) {
            throw $this->createNotFoundException();
        }

        return $proposal;
    }
}
