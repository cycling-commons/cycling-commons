<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Roadmap\Entity\RoadmapProposal;
use App\Roadmap\Entity\RoadmapProposalImage;
use App\Roadmap\ProposalIntake;
use App\Roadmap\RoadmapRefused;
use App\Roadmap\RoadmapSay;
use App\Routing\LocalePrefix;
use App\Routing\LocalizedPath;
use App\Support\ScreenshotStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A rider's own roadmap proposals: the form to send one, and the list of
 * what they sent and what became of it. Closed while the rider's say is off
 * (RoadmapSay); a rider's picture stays reachable for them either way.
 *
 * The list is the record. The decision also reaches the rider's inbox, but an
 * idea that is still waiting has no message yet, and this page is where the
 * rider sees that it arrived.
 *
 * @see docs/specs/roadmap-and-changelog.md §8
 *
 * @api
 */
#[Route(LocalePrefix::PATHS)]
#[IsGranted('ROLE_USER')]
final class RoadmapProposalController extends AbstractController
{
    public const string CSRF_ID = 'roadmap-propose';

    public function __construct(
        private readonly ProposalIntake $intake,
        private readonly EntityManagerInterface $em,
        private readonly RoadmapSay $say,
    ) {
    }

    #[Route(LocalizedPath::ROADMAP_PROPOSE, name: 'roadmap_propose', methods: ['GET', 'POST'])]
    public function propose(Request $request): Response
    {
        if (!$this->say->isLive()) {
            throw $this->createNotFoundException();
        }
        $user = $this->getUser();
        \assert($user instanceof User);

        $error = null;
        $kept = ['title' => '', 'problem' => '', 'idea' => '', 'benefit' => ''];
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            $form = $request->request->all();
            $token = $form['_token'] ?? null;
            if (!$this->isCsrfTokenValid(self::CSRF_ID, \is_string($token) ? $token : '')) {
                $error = 'flash.invalid_token';
            } else {
                $file = $request->files->get('image');
                try {
                    $proposal = $this->intake->submit($user, $form, $file instanceof UploadedFile ? $file : null, $request->getLocale());
                    $this->addFlash('success', 'roadmap.propose.sent');

                    return $this->redirect($this->generateUrl('roadmap_propose').'#idea-'.(int) $proposal->getId(), Response::HTTP_SEE_OTHER);
                } catch (RoadmapRefused $e) {
                    $error = $e->messageKey();
                }
            }
            foreach (array_keys($kept) as $field) {
                $kept[$field] = \is_string($form[$field] ?? null) ? $form[$field] : '';
            }
            $kept['image_may_show'] = '1' === ($form['image_may_show'] ?? null);
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        $ideas = $this->intake->ideasOf((int) $user->getId());
        $images = [];
        foreach ($ideas as $idea) {
            $image = $this->intake->imageOf($idea);
            if (null !== $image) {
                $images[(int) $idea->getId()] = $image;
            }
        }

        return $this->render('roadmap/propose.html.twig', [
            'page_title' => 'roadmap.propose.meta_title',
            'nav_active' => '',
            'csrf_id' => self::CSRF_ID,
            'error' => $error,
            'kept' => $kept,
            'ideas' => $ideas,
            'images' => $images,
            'email_verified' => $user->isEmailVerified(),
            'title_max' => RoadmapProposal::TITLE_MAX,
            'text_max' => RoadmapProposal::TEXT_MAX,
            'benefit_max' => RoadmapProposal::BENEFIT_MAX,
            'max_shot_mb' => ScreenshotStore::MAX_UPLOAD_MB,
            'max_shot_bytes' => ScreenshotStore::MAX_UPLOAD_BYTES,
        ], new Response(null, $status));
    }

    /**
     * The picture on one of the rider's own ideas, once the worker drew it.
     * The desk has its own route; nobody else sees it.
     */
    #[Route('/roadmap/ideas/{id}/image', name: 'roadmap_idea_image', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function image(int $id): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);
        $proposal = $this->em->find(RoadmapProposal::class, $id);
        if (!$proposal instanceof RoadmapProposal || $proposal->getUserId() !== (int) $user->getId()) {
            throw $this->createNotFoundException();
        }

        return self::serveImage($this->intake->imageOf($proposal), $id);
    }

    /** One picture, the bug screenshot's headers: never sniffed, never cached, never indexed. */
    public static function serveImage(?RoadmapProposalImage $image, int $proposalId): Response
    {
        if (null === $image || !$image->isReady()) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        return new Response($image->getBytes(), Response::HTTP_OK, [
            'Content-Type' => $image->getMimeType(),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => \sprintf('inline; filename="idea-%d.webp"', $proposalId),
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
