<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Media\Entity\MediaUpload;
use App\Media\MediaStatus;
use App\Media\MediaTakedownService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * The uploader asking for their own photo to come down
 * (docs/specs/photo-uploads.md §6b).
 *
 * Prefix-free and unlocalized, matching PhotoPageController: this posts back to
 * the page whose URL is baked into the stored files, and the two must not
 * disagree about where they live.
 *
 * @api Instantiated by Symfony's router — `@api` tells Psalm this is a live
 *      entry point, not dead code.
 */
#[IsGranted('ROLE_USER')]
final class MediaTakedownController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaTakedownService $takedowns,
    ) {
    }

    #[Route(
        '/photo/{uuid}/takedown',
        name: 'photo_takedown',
        requirements: ['uuid' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}'],
        methods: ['POST'],
    )]
    public function request(string $uuid, Request $httpRequest): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $upload = $this->em->find(MediaUpload::class, Uuid::fromString($uuid));

        // One 404 for every way this can be the wrong photo — not theirs, not
        // published, already asked about. Distinguishing them would let anyone
        // holding a photo URL learn who uploaded it by watching which failure
        // they get.
        if (null === $upload
            || $upload->getUserId() !== (int) $user->getId()
            || MediaStatus::Approved !== $upload->getStatus()
            || null !== $upload->getObjectsDeletedAt()
            || $upload->isTakedownPending()
        ) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('photo_takedown', $httpRequest->request->get('_token'))) {
            $this->addFlash('error', 'flash.invalid_token');

            return $this->redirectToRoute('photo_page', ['uuid' => $uuid]);
        }

        try {
            $this->takedowns->request($upload, (string) $httpRequest->request->get('reason', ''));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('photo_page', ['uuid' => $uuid]);
        }

        $this->addFlash('success', 'flash.takedown_requested');

        return $this->redirectToRoute('photo_page', ['uuid' => $uuid]);
    }
}
