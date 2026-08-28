<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\Entity\Item;
use App\Entity\User;
use App\Media\Entity\MediaUpload;
use App\Media\MediaDecisionService;
use App\Media\MediaStatus;
use App\Media\MediaStorage;
use App\Media\PhotoAttribution;
use App\Media\XmpRights;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Stable photo page; attribution resolved live, never frozen in the file.
 *
 * @see docs/specs/photo-uploads.md §5d
 *
 * @api
 */
final class PhotoPageController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaStorage $storage,
    ) {
    }

    #[Route(
        '/photo/{uuid}',
        name: 'photo_page',
        requirements: ['uuid' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}'],
        methods: ['GET'],
    )]
    public function show(string $uuid): Response
    {
        $upload = $this->em->find(MediaUpload::class, Uuid::fromString($uuid));

        // docs/specs/photo-uploads.md §6b / §6c / §6d — unpublished, withheld, and legal-hold look the same.
        if (null === $upload
            || MediaStatus::Approved !== $upload->getStatus()
            || !$upload->hasPublishedObjects()
            || null !== $upload->getObjectsDeletedAt()
            || $upload->isTakedownWithheld()
            || $upload->isEscalated()
        ) {
            return $this->render('media/photo.html.twig', [
                'page_title' => 'media.page.unpublished_title',
                'page_description' => 'media.page.unpublished_title',
                'nav_active' => '',
                'photo' => null,
                'takedown_pending' => null !== $upload
                    && $upload->isTakedownPending()
                    && $this->isUploader($upload),
                'can_request_takedown' => false,
            ], new Response('', Response::HTTP_NOT_FOUND));
        }

        $attribution = $this->attribution($upload);
        $bucket = $upload->getStorageBucket();
        $prefix = $upload->getPathPrefix();

        return $this->render('media/photo.html.twig', [
            'page_title' => 'media.page.title',
            'page_description' => 'media.page.description',
            'nav_active' => '',
            'takedown_pending' => false,
            'can_request_takedown' => $this->isUploader($upload),
            'photo_uuid' => $upload->getId()->toRfc4122(),
            'photo' => [
                'sm' => $this->storage->url($bucket, $prefix, 'sm'),
                'lg' => $this->storage->url($bucket, $prefix, 'lg'),
                'orig' => $this->storage->url($bucket, $prefix, 'orig'),
                'width' => $upload->getWidth(),
                'height' => $upload->getHeight(),
                'license' => MediaDecisionService::LICENSE,
                'licenseUrl' => XmpRights::LICENSE_URL,
                'takenAt' => $upload->getTakenAt()?->format('Y-m'),
                // What a screen reader announces. Null falls back in the
                // template to the item's name, then to the generic string.
                // @see docs/specs/photo-uploads.md §5e
                'alt' => $upload->getAltText(),
                'credit' => $attribution->name,
                'profileUuid' => $attribution->profileUuid,
                'viaApp' => $attribution->viaApp,
                'item' => $this->item($upload),
            ],
        ]);
    }

    /**
     * Live attribution.
     *
     * @see docs/specs/photo-uploads.md §5d
     */
    private function attribution(MediaUpload $upload): PhotoAttribution
    {
        $userId = $upload->getUserId();
        if (null === $userId) {
            // docs/specs/photo-uploads.md §6 — departing rider's credit choice.
            return new PhotoAttribution($upload->getCreditFrozen() ?? '');
        }

        $user = $this->em->find(User::class, $userId);
        if (null === $user || !$user->isPublicProfile()) {
            return new PhotoAttribution('');
        }

        return new PhotoAttribution($user->getDisplayName(), $user->getUuid()?->toRfc4122());
    }

    /** Uploader-only takedown affordance.
     *
     * @see docs/specs/photo-uploads.md §6b
     */
    private function isUploader(MediaUpload $upload): bool
    {
        $viewer = $this->getUser();

        return $viewer instanceof User
            && null !== $upload->getUserId()
            && (int) $viewer->getId() === $upload->getUserId();
    }

    /** @return array{id: int, name: string}|null */
    private function item(MediaUpload $upload): ?array
    {
        $itemId = $upload->getItemId();
        if (null === $itemId) {
            return null;
        }
        $item = $this->em->find(Item::class, $itemId);

        return null !== $item ? ['id' => (int) $item->getId(), 'name' => $item->getName()] : null;
    }
}
