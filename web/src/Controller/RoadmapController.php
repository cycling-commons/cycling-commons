<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Content\ReleaseNotes;
use App\Entity\User;
use App\Roadmap\Entity\RoadmapProposal;
use App\Roadmap\Entity\RoadmapProposalImage;
use App\Roadmap\RoadmapItems;
use App\Roadmap\RoadmapMarks;
use App\Roadmap\RoadmapSay;
use App\Routing\LocalePrefix;
use App\Routing\LocalizedPath;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What is coming, and the rider's say in it.
 *
 * The roadmap tab lists the live items from the database (RoadmapItems). With
 * the rider's say switched on (RoadmapSay, the `roadmap.say_live` setting) a
 * second tab lists the riders' proposals open for votes, and a signed-in
 * rider votes for and follows items and proposals, and sends a proposal of
 * their own (RoadmapProposalController). A visitor without an account reads
 * the same lists with the counts, and a sign-in link where the buttons would
 * be, so the page stays shareable in the public cache. The tab is in the URL
 * (`?tab=proposals`) for the same reason.
 *
 * The list is unfinished work. An item that shipped moves to "Latest
 * additions" beside it, the newest few, and to the Done page, all of them.
 * How it landed, release by release, is the changelog, linked from the foot.
 *
 * @see docs/specs/roadmap-and-changelog.md §8
 *
 * @api
 */
#[Route(LocalePrefix::PATHS)]
final class RoadmapController extends AbstractController
{
    public const string CSRF_ID = 'roadmap-mark';

    /** How many shipped items "Latest additions" shows; the rest are on the Done page. */
    public const int LATEST_DONE = 5;

    public function __construct(
        private readonly RoadmapItems $items,
        private readonly RoadmapMarks $marks,
        private readonly RoadmapSay $say,
        private readonly EntityManagerInterface $em,
        private readonly RateLimiterFactory $roadmapMarkLimiter,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(LocalizedPath::ROADMAP, name: 'roadmap', methods: ['GET'])]
    public function roadmap(Request $request): Response
    {
        $user = $this->getUser();
        $userId = $user instanceof User ? (int) $user->getId() : null;
        $live = $this->say->isLive();
        $tab = $live && 'proposals' === $request->query->getString('tab') ? 'proposals' : 'roadmap';

        return $this->render('pages/roadmap.html.twig', [
            'page_title' => 'meta.roadmap_title',
            'page_description' => 'meta.roadmap_description',
            'nav_active' => '',
            'statuses' => ReleaseNotes::STATUSES,
            'say_live' => $live,
            'tab' => $tab,
            'roadmap' => 'roadmap' === $tab ? $this->items->rows($request->getLocale(), $userId) : [],
            'proposals' => 'proposals' === $tab ? $this->items->proposalRows($userId) : [],
            'proposal_count' => $live ? \count($this->items->openProposals()) : 0,
            'csrf_id' => self::CSRF_ID,
            'latest' => ReleaseNotes::latestRelease(),
            'latest_done' => $this->items->shippedRows($request->getLocale(), self::LATEST_DONE),
        ]);
    }

    /**
     * Everything the roadmap promised that is now on the site, newest first,
     * by the month it shipped.
     *
     * @see docs/specs/roadmap-and-changelog.md §8.2a
     */
    #[Route(LocalizedPath::ROADMAP_DONE, name: 'roadmap_done', methods: ['GET'])]
    public function done(Request $request): Response
    {
        return $this->render('pages/roadmap_done.html.twig', [
            'page_title' => 'meta.roadmap_done_title',
            'page_description' => 'meta.roadmap_done_description',
            'nav_active' => '',
            'done' => $this->items->shippedRows($request->getLocale()),
        ]);
    }

    /**
     * A vote or a follow, flipped. The page posts it in the background
     * (roadmap/marks.js) and gets the new state back; without script it is a
     * plain form and lands back on the item.
     */
    #[Route('/roadmap/mark', name: 'roadmap_mark', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function mark(Request $request): Response
    {
        if (!$this->say->isLive()) {
            throw $this->createNotFoundException();
        }
        $user = $this->getUser();
        \assert($user instanceof User);
        $json = \in_array('application/json', $request->getAcceptableContentTypes(), true);

        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid(self::CSRF_ID, \is_string($token) ? $token : '')) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $ref = (string) $request->request->get('ref', '');
        $do = (string) $request->request->get('do', '');
        $anchor = $this->items->anchorOf($ref);
        if (null === $anchor || !\in_array($do, ['vote', 'follow'], true)) {
            return $this->refuse($json, 'roadmap.mark_gone', Response::HTTP_NOT_FOUND);
        }
        if (!$user->isEmailVerified()) {
            return $this->refuse($json, 'roadmap.mark_unverified', Response::HTTP_FORBIDDEN);
        }
        if (!$this->roadmapMarkLimiter->create('user-'.(int) $user->getId())->consume()->isAccepted()) {
            return $this->refuse($json, 'roadmap.mark_too_fast', Response::HTTP_TOO_MANY_REQUESTS);
        }

        $userId = (int) $user->getId();
        $on = 'vote' === $do ? $this->marks->toggleVote($userId, $ref) : $this->marks->toggleFollow($userId, $ref);

        if ($json) {
            return new JsonResponse([
                'on' => $on,
                'votes' => $this->marks->voteCounts([$ref])[$ref] ?? 0,
            ]);
        }

        $params = str_starts_with($anchor, 'idea-') ? ['tab' => 'proposals'] : [];

        return $this->redirect($this->generateUrl('roadmap', $params).'#'.$anchor, Response::HTTP_SEE_OTHER);
    }

    /**
     * A proposal's picture on the public page, served only while
     * RoadmapItems::pictureIsPublic() says so: the rider allowed it, the desk
     * chose it, and it belongs to something on the page. Anything else is the
     * same 404 as a picture that does not exist.
     */
    #[Route('/roadmap/pictures/{id}', name: 'roadmap_picture', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function picture(int $id): Response
    {
        $proposal = $this->em->find(RoadmapProposal::class, $id);
        if (!$proposal instanceof RoadmapProposal || !$this->items->pictureIsPublic($proposal)) {
            throw $this->createNotFoundException();
        }
        $image = $this->em->getRepository(RoadmapProposalImage::class)->findOneBy(['proposal' => $proposal]);
        if (!$image instanceof RoadmapProposalImage || !$image->isPublic()) {
            throw $this->createNotFoundException();
        }

        $response = new Response($image->getBytes(), Response::HTTP_OK, [
            'Content-Type' => $image->getMimeType(),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => \sprintf('inline; filename="proposal-%d.webp"', $id),
            'Cache-Control' => 'public, max-age=600',
        ]);
        // Symfony would make it private once a session is open; this answer
        // is the same for everybody.
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        return $response;
    }

    private function refuse(bool $json, string $messageKey, int $status): Response
    {
        if ($json) {
            return new JsonResponse(['error' => $this->translator->trans($messageKey)], $status);
        }
        $this->addFlash('error', $messageKey);

        return $this->redirectToRoute('roadmap', [], Response::HTTP_SEE_OTHER);
    }
}
