<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Content\ReleaseNotes;
use App\Roadmap\Entity\RoadmapItem;
use App\Roadmap\ItemDesk;
use App\Roadmap\ItemState;
use App\Roadmap\ProposalIntake;
use App\Roadmap\RoadmapItems;
use App\Roadmap\RoadmapMarks;
use App\Roadmap\RoadmapRefused;
use App\Roadmap\RoadmapSay;
use App\Routing\LocalePrefix;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The roadmap desk's items: the live roadmap by group, a form to add an
 * item, and one page per item to reword it in each language, move it, and
 * take it off the roadmap as shipped or dropped.
 *
 * Admins only, like the proposals beside it (ModerateIdeasController): what
 * the project builds next is the project's call.
 *
 * @see docs/specs/roadmap-and-changelog.md §8
 *
 * @api
 */
#[Route(LocalePrefix::PATHS)]
#[IsGranted('ROLE_ADMIN')]
final class ModerateRoadmapController extends AbstractController
{
    public const string CSRF_ID = 'roadmap-item-desk';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ItemDesk $desk,
        private readonly RoadmapItems $items,
        private readonly RoadmapMarks $marks,
        private readonly ProposalIntake $intake,
        private readonly RoadmapSay $say,
    ) {
    }

    #[Route('/moderate/roadmap', name: 'moderate_roadmap', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $items = $this->items->liveItems();

        return $this->render('moderate/roadmap_items.html.twig', [
            'page_title' => 'roadmap.items.title',
            'items' => $items,
            'locale' => $request->getLocale(),
            'votes' => $this->marks->voteCounts(array_map(static fn (RoadmapItem $i): string => $i->getRef(), $items)),
            'groups' => ReleaseNotes::STATUSES,
            'pending' => $this->intake->pendingCount(),
            'say_live' => $this->say->isLive(),
            'csrf_id' => self::CSRF_ID,
        ]);
    }

    #[Route('/moderate/roadmap/new', name: 'moderate_roadmap_new', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        $error = null;
        $form = $request->request->all();
        if ($request->isMethod('POST')) {
            if (!$this->tokenOk($form)) {
                return $this->redirectToRoute('moderate_roadmap_new', [], Response::HTTP_SEE_OTHER);
            }
            try {
                $item = $this->desk->create((string) ($form['group'] ?? ''), self::texts($form), self::issue($form));
                $this->addFlash('success', 'roadmap.items.created');

                return $this->redirectToRoute('moderate_roadmap_item', ['id' => $item->getId()], Response::HTTP_SEE_OTHER);
            } catch (RoadmapRefused $e) {
                $error = $e->messageKey();
            }
        }

        return $this->render('moderate/roadmap_item.html.twig', [
            'page_title' => 'roadmap.items.new_title',
            'item' => null,
            'draft' => $request->isMethod('POST') ? $form : null,
            'error' => $error,
            'locales' => ItemDesk::locales(),
            'groups' => ReleaseNotes::STATUSES,
            'csrf_id' => self::CSRF_ID,
        ], new Response(null, null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/moderate/roadmap/{id}', name: 'moderate_roadmap_item', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function item(int $id, Request $request): Response
    {
        $item = $this->find($id);
        $error = null;
        $form = $request->request->all();
        if ($request->isMethod('POST')) {
            if (!$this->tokenOk($form)) {
                return $this->redirectToRoute('moderate_roadmap_item', ['id' => $id], Response::HTTP_SEE_OTHER);
            }
            try {
                $this->apply($item, $form);
                $this->addFlash('success', 'roadmap.items.saved');

                return $item->isLive()
                    ? $this->redirectToRoute('moderate_roadmap_item', ['id' => $id], Response::HTTP_SEE_OTHER)
                    : $this->redirectToRoute('moderate_roadmap', [], Response::HTTP_SEE_OTHER);
            } catch (RoadmapRefused $e) {
                $error = $e->messageKey();
            }
        }

        return $this->render('moderate/roadmap_item.html.twig', [
            'page_title' => 'roadmap.items.edit_title',
            'item' => $item,
            'draft' => null !== $error ? $form : null,
            'error' => $error,
            'votes' => $this->marks->voteCounts([$item->getRef()])[$item->getRef()] ?? 0,
            'followers' => \count($this->marks->followers($item->getRef())),
            'locales' => ItemDesk::locales(),
            'groups' => ReleaseNotes::STATUSES,
            'csrf_id' => self::CSRF_ID,
        ], new Response(null, null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /** Up or down inside the group, from the list. */
    #[Route('/moderate/roadmap/{id}/shift', name: 'moderate_roadmap_shift', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function shift(int $id, Request $request): Response
    {
        $form = $request->request->all();
        if ($this->tokenOk($form)) {
            try {
                $this->desk->shift($this->find($id), 'up' === ($form['dir'] ?? '') ? -1 : 1);
            } catch (RoadmapRefused $e) {
                $this->addFlash('error', $e->messageKey());
            }
        }

        return $this->redirect($this->generateUrl('moderate_roadmap').'#item-'.$id, Response::HTTP_SEE_OTHER);
    }

    /**
     * One form, three outcomes: save (words, issue, and a move when the group
     * changed), shipped, or removed.
     *
     * @param array<string, mixed> $form
     *
     * @throws RoadmapRefused
     */
    private function apply(RoadmapItem $item, array $form): void
    {
        $do = (string) ($form['do'] ?? 'save');
        if ('ship' === $do || 'remove' === $do) {
            $this->desk->close($item, 'ship' === $do ? ItemState::Shipped : ItemState::Removed);

            return;
        }
        $group = (string) ($form['group'] ?? $item->getStatus());
        if (!\in_array($group, ReleaseNotes::STATUSES, true)) {
            throw new RoadmapRefused('roadmap.items.error_group');
        }
        $this->desk->reword($item, self::texts($form), self::issue($form));
        if ($group !== $item->getStatus()) {
            $this->desk->move($item, $group);
        }
    }

    /**
     * @param array<string, mixed> $form
     *
     * @return array<string, array{title?: mixed, body?: mixed}>
     */
    private static function texts(array $form): array
    {
        $texts = [];
        $raw = \is_array($form['text'] ?? null) ? $form['text'] : [];
        foreach (ItemDesk::locales() as $locale) {
            $one = \is_array($raw[$locale] ?? null) ? $raw[$locale] : [];
            $texts[$locale] = ['title' => $one['title'] ?? null, 'body' => $one['body'] ?? null];
        }

        return $texts;
    }

    /**
     * @param array<string, mixed> $form
     *
     * @throws RoadmapRefused
     */
    private static function issue(array $form): ?int
    {
        $raw = trim(\is_string($form['issue'] ?? null) ? $form['issue'] : '');
        $raw = ltrim($raw, '#');
        if ('' === $raw) {
            return null;
        }
        if (!ctype_digit($raw) || (int) $raw < 1 || (int) $raw > 9_999_999) {
            throw new RoadmapRefused('roadmap.items.error_issue');
        }

        return (int) $raw;
    }

    /** @param array<string, mixed> $form */
    private function tokenOk(array $form): bool
    {
        $token = $form['_token'] ?? null;
        if ($this->isCsrfTokenValid(self::CSRF_ID, \is_string($token) ? $token : '')) {
            return true;
        }
        $this->addFlash('notice', 'flash.invalid_token');

        return false;
    }

    private function find(int $id): RoadmapItem
    {
        $item = $this->em->find(RoadmapItem::class, $id);
        if (!$item instanceof RoadmapItem) {
            throw $this->createNotFoundException();
        }

        return $item;
    }
}
