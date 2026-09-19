<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Messaging\CuratorRoom;
use App\Messaging\CuratorRoomCategory;
use App\Messaging\CuratorRoomPin;
use App\Messaging\Entity\CuratorPost;
use App\Messaging\Entity\CuratorPostImage;
use App\Moderation\ModerationScopeProvider;
use App\Routing\LocalePrefix;
use App\Support\ScreenshotRejected;
use App\Support\ScreenshotStore;
use App\Support\StoredImage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The curator room: the in-desk board the rulebook points at.
 *
 * Curators only, like every other desk. Unscoped on purpose: the rulebook's
 * "if an item is out of reach, ask the room" only works if the room reaches
 * past the asker's moderation area (§13.6).
 *
 * @see docs/specs/moderation-and-contribution.md §13
 *
 * @api
 */
#[Route(LocalePrefix::PATHS)]
#[IsGranted('ROLE_CURATOR')]
final class ModerateRoomController extends AbstractController
{
    public function __construct(
        private readonly CuratorRoom $room,
        private readonly ModerationScopeProvider $scopeProvider,
        private readonly ScreenshotStore $images,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/moderate/room', name: 'moderate_room', methods: ['GET'])]
    public function index(Request $request): Response
    {
        /** @var User $curator */
        $curator = $this->getUser();
        $curatorId = (int) $curator->getId();
        $view = $this->view($request);

        $board = $this->room->board($curatorId, $view);

        // Stamped before the response renders, so the tab this page owns does
        // not badge the page you are looking at.
        $this->room->markSeen($curatorId);

        return $this->render('moderate/room.html.twig', [
            'page_title' => 'meta.moderate_room_title',
            'page_description' => 'meta.moderate_room_description',
            'nav_active' => 'moderate_room',
            'view' => $view,
            'categories' => CuratorRoomCategory::cases(),
            'pinned' => $board['pinned'],
            'posts' => $board['posts'],
            'mod_scope_names' => $this->scopeProvider->describe($curator),
        ]);
    }

    /**
     * One picture on a post, to a curator who may see that post. Served from
     * the database with no caching: this is desk material (§13.3).
     */
    #[Route('/moderate/room/image/{id}', name: 'moderate_room_image', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function image(int $id): Response
    {
        /** @var User $curator */
        $curator = $this->getUser();
        $image = $this->room->image($id, (int) $curator->getId());
        if (!$image instanceof CuratorPostImage) {
            throw $this->createNotFoundException();
        }

        $response = new Response($image->getBytes());
        $response->headers->set('Content-Type', $image->getMimeType());
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Disposition', 'inline');
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    /**
     * The composer's "about submission" search: an id, a word of the title,
     * or a region name. Curators only, like the room; nothing here that the
     * queue does not already show them.
     */
    #[Route('/moderate/room/submissions', name: 'moderate_room_submissions', methods: ['GET'])]
    public function submissions(Request $request): JsonResponse
    {
        return new JsonResponse($this->room->searchSubmissions($request->query->getString('q')));
    }

    #[Route('/moderate/room/post', name: 'moderate_room_post', methods: ['POST'])]
    public function post(Request $request): Response
    {
        $this->assertToken($request, 'moderate-room-post');

        /** @var User $curator */
        $curator = $this->getUser();
        $view = $this->view($request);

        // Read as strings, not getInt(): the "everyone" option and the empty
        // submission box both post "", and InputBag::getInt() throws on that
        // rather than returning the default.
        $recipientId = (int) $request->request->getString('to') ?: null;
        $category = CuratorRoomCategory::tryFrom($request->request->getString('category'));
        $body = $request->request->getString('body');

        // `about` is what the search box picked. Without the script, or when
        // a curator just types a number, `about_q` carries "123" or "SUB-123".
        $aboutId = (int) $request->request->getString('about') ?: null;
        if (null === $aboutId && preg_match('/^\s*(?:SUB-?)?(\d{1,12})\s*$/i', $request->request->getString('about_q'), $m)) {
            $aboutId = (int) $m[1];
        }

        $imageIds = $this->imageIds($request);
        $pin = CuratorRoomPin::tryFrom($request->request->getString('pin')) ?? CuratorRoomPin::None;

        try {
            $this->room->post(
                (int) $curator->getId(),
                $category,
                $recipientId,
                $body,
                $aboutId,
                $this->renderUploads($request),
                $imageIds,
                $pin,
            );
            $this->addFlash('success', 'room.flash.posted');
        } catch (\InvalidArgumentException|ScreenshotRejected $e) {
            $this->addFlash('danger', $e->getMessage());
            $this->addFlash('room_draft', $body);

            return $this->redirectToRoute('moderate_room_new', CuratorRoomCategory::VIEW_ALL === $view ? [] : ['c' => $view]);
        }

        return $this->backToRoom($view);
    }

    /** The composer, on its own page. The board only lists. */
    #[Route('/moderate/room/new', name: 'moderate_room_new', methods: ['GET'])]
    public function newForm(Request $request): Response
    {
        /** @var User $curator */
        $curator = $this->getUser();
        $curatorId = (int) $curator->getId();

        return $this->render('moderate/room_new.html.twig', [
            'page_title' => 'meta.moderate_room_title',
            'page_description' => 'meta.moderate_room_description',
            'nav_active' => 'moderate_room',
            'view' => $this->view($request),
            'categories' => CuratorRoomCategory::cases(),
            'curators' => $this->room->curators($curatorId),
            'body_max' => CuratorRoom::BODY_MAX_LENGTH,
            'images_max' => CuratorPost::MAX_IMAGES,
            'image_bytes_max' => ScreenshotStore::MAX_UPLOAD_BYTES,
            'draft' => $this->draft($request),
            'mod_scope_names' => $this->scopeProvider->describe($curator),
        ]);
    }

    /** The author's own post, every field open again. */
    #[Route('/moderate/room/{id}/edit', name: 'moderate_room_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function editForm(int $id, Request $request): Response
    {
        /** @var User $curator */
        $curator = $this->getUser();
        $curatorId = (int) $curator->getId();
        $post = $this->room->own($id, $curatorId);
        if (!$post instanceof CuratorPost) {
            throw $this->createNotFoundException();
        }
        $about = $post->getAboutSubmissionId();

        return $this->render('moderate/room_edit.html.twig', [
            'page_title' => 'meta.moderate_room_title',
            'page_description' => 'meta.moderate_room_description',
            'nav_active' => 'moderate_room',
            'view' => $this->view($request),
            'post' => $post,
            'about_title' => null !== $about ? $this->room->submissionTitle($about) : null,
            'categories' => CuratorRoomCategory::cases(),
            'curators' => $this->room->curators($curatorId),
            'body_max' => CuratorRoom::BODY_MAX_LENGTH,
            'images_max' => CuratorPost::MAX_IMAGES,
            'image_bytes_max' => ScreenshotStore::MAX_UPLOAD_BYTES,
            'draft' => $this->draft($request),
            'mod_scope_names' => $this->scopeProvider->describe($curator),
        ]);
    }

    #[Route('/moderate/room/{id}/edit', name: 'moderate_room_edit_save', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function editSave(int $id, Request $request): Response
    {
        $this->assertToken($request, 'moderate-room-post');

        /** @var User $curator */
        $curator = $this->getUser();
        $view = $this->view($request);
        $recipientId = (int) $request->request->getString('to') ?: null;
        $category = CuratorRoomCategory::tryFrom($request->request->getString('category'));
        $body = $request->request->getString('body');
        $aboutId = (int) $request->request->getString('about') ?: null;
        if (null === $aboutId && preg_match('/^\s*(?:SUB-?)?(\d{1,12})\s*$/i', $request->request->getString('about_q'), $m)) {
            $aboutId = (int) $m[1];
        }
        $pin = CuratorRoomPin::tryFrom($request->request->getString('pin')) ?? CuratorRoomPin::None;
        $drop = array_values(array_filter(array_map('intval', $request->request->all('drop')), static fn (int $i): bool => $i > 0));

        try {
            $this->room->edit(
                $id,
                (int) $curator->getId(),
                $category,
                $recipientId,
                $body,
                $aboutId,
                $pin,
                $this->renderUploads($request),
                $this->imageIds($request),
                $drop,
            );
            $this->addFlash('success', 'room.flash.edited');
        } catch (\InvalidArgumentException|ScreenshotRejected $e) {
            $this->addFlash('danger', $e->getMessage());
            $this->addFlash('room_draft', $body);

            return $this->redirectToRoute('moderate_room_edit', ['id' => $id] + (CuratorRoomCategory::VIEW_ALL === $view ? [] : ['c' => $view]));
        }

        return $this->backToRoom($view);
    }

    /**
     * One picture from the composer's uploader, sent as soon as it is chosen so
     * the bar can show real progress. Rendered by the server the way a bug
     * screenshot is; held until the post claims it. JSON in return.
     */
    #[Route('/moderate/room/upload', name: 'moderate_room_upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('moderate-room-upload', $request->request->getString('_token'))) {
            return new JsonResponse(['error' => $this->translator->trans('room.error.upload_failed')], Response::HTTP_FORBIDDEN);
        }
        /** @var User $curator */
        $curator = $this->getUser();
        $file = $request->files->get('image');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return new JsonResponse(['error' => $this->translator->trans('support.bug.error.shot_unreadable')], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $path = $file->getPathname();
        if (filesize($path) > ScreenshotStore::MAX_UPLOAD_BYTES) {
            return new JsonResponse(['error' => $this->translator->trans('support.bug.error.shot_too_large')], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $image = $this->images->render((string) file_get_contents($path), 'webp', CuratorPostImage::MAX_BYTES);
        } catch (ScreenshotRejected $e) {
            return new JsonResponse(['error' => $this->translator->trans($e->translationKey())], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $upload = $this->room->upload((int) $curator->getId(), $image);

        return new JsonResponse([
            'id' => $upload->getId(),
            'width' => $upload->getWidth(),
            'height' => $upload->getHeight(),
            'bytes' => $upload->getByteSize(),
            'url' => $this->generateUrl('moderate_room_image', ['id' => $upload->getId()]),
        ], Response::HTTP_CREATED);
    }

    /** Take back a picture uploaded and not yet posted. */
    #[Route('/moderate/room/upload/{id}/remove', name: 'moderate_room_upload_remove', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function removeUpload(int $id, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('moderate-room-upload', $request->request->getString('_token'))) {
            return new JsonResponse(['error' => $this->translator->trans('room.error.upload_failed')], Response::HTTP_FORBIDDEN);
        }
        /** @var User $curator */
        $curator = $this->getUser();

        return new JsonResponse(['removed' => $this->room->removeUnclaimed($id, (int) $curator->getId())]);
    }

    #[Route('/moderate/room/pin', name: 'moderate_room_pin', methods: ['POST'])]
    public function pin(Request $request): Response
    {
        $this->assertToken($request, 'moderate-room-pin');

        $view = $this->view($request);
        $pin = CuratorRoomPin::tryFrom($request->request->getString('pin')) ?? CuratorRoomPin::None;

        try {
            $this->room->pin((int) $request->request->getString('id'), $pin);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->backToRoom($view);
    }

    #[Route('/moderate/room/delete', name: 'moderate_room_delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        $this->assertToken($request, 'moderate-room-delete');

        /** @var User $curator */
        $curator = $this->getUser();
        $view = $this->view($request);

        if (!$this->room->deleteOwn((int) $request->request->getString('id'), (int) $curator->getId())) {
            $this->addFlash('danger', 'room.error.not_yours');
        }

        return $this->backToRoom($view);
    }

    /**
     * Every picture the composer sent, drawn again by the server. Refuses the
     * post rather than dropping a picture quietly: a curator who attached a
     * screenshot of the problem wants to know it did not arrive.
     *
     * @return list<StoredImage>
     *
     * @throws ScreenshotRejected with a translation key
     */
    private function renderUploads(Request $request): array
    {
        $files = array_values(array_filter($request->files->all('image'), static fn ($f): bool => $f instanceof UploadedFile));
        if (\count($files) > CuratorPost::MAX_IMAGES) {
            throw new \InvalidArgumentException('room.error.too_many_images');
        }
        $out = [];
        foreach ($files as $file) {
            if (!$file->isValid()) {
                throw new ScreenshotRejected('support.bug.error.shot_unreadable');
            }
            $path = $file->getPathname();
            if (filesize($path) > ScreenshotStore::MAX_UPLOAD_BYTES) {
                throw new ScreenshotRejected('support.bug.error.shot_too_large');
            }
            $out[] = $this->images->render((string) file_get_contents($path), 'webp', CuratorPostImage::MAX_BYTES);
        }

        return $out;
    }

    /**
     * Ids of pictures the composer's uploader already sent, a JSON list.
     *
     * @return list<int>
     */
    private function imageIds(Request $request): array
    {
        try {
            $decoded = json_decode($request->request->getString('images') ?: '[]', true, 2, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!\is_array($decoded)) {
            return [];
        }
        $ids = [];
        foreach ($decoded as $v) {
            if ((\is_int($v) || (\is_string($v) && ctype_digit($v))) && (int) $v > 0) {
                $ids[] = (int) $v;
            }
        }

        return $ids;
    }

    /** The words of a post that was refused, so the composer shows them again. */
    private function draft(Request $request): string
    {
        $session = $request->getSession();
        if (!$session instanceof FlashBagAwareSessionInterface) {
            return '';
        }
        $drafts = $session->getFlashBag()->get('room_draft');

        return isset($drafts[0]) && \is_string($drafts[0]) ? $drafts[0] : '';
    }

    private function assertToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    /**
     * The active view: a category value, `direct`, `root`, or All for anything
     * else. An unknown value falls back rather than 404ing, because a stale
     * bookmark should still open the room.
     */
    private function view(Request $request): string
    {
        $raw = $request->isMethod('POST')
            ? $request->request->getString('c')
            : $request->query->getString('c');

        if (CuratorRoomCategory::VIEW_DIRECT === $raw || CuratorRoomCategory::VIEW_ROOT === $raw) {
            return $raw;
        }

        $category = CuratorRoomCategory::tryFrom($raw);

        return null !== $category ? $category->value : CuratorRoomCategory::VIEW_ALL;
    }

    private function backToRoom(string $view): Response
    {
        return $this->redirectToRoute(
            'moderate_room',
            CuratorRoomCategory::VIEW_ALL === $view ? [] : ['c' => $view],
        );
    }
}
