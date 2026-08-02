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
 * The page every stored photo points at (docs/specs/photo-uploads.md §5d). Its
 * URL is written into the file itself as xmpRights:WebStatement and
 * cc:attributionURL, so a reuser who has nothing but the image can still find
 * out what they may do with it and whom to credit.
 *
 * Deliberately unlocalized and prefix-free: this URL is baked into files that
 * will outlive any routing decision made later, so it is the plainest stable
 * thing the app can commit to.
 *
 * The attribution is RESOLVED HERE, on every request, never read from a copy
 * frozen at upload time. That single fact is what makes attribution revocable:
 * a rider who goes private, or leaves, changes what every copy of their photo
 * credits — including copies downloaded and mirrored years ago. It is also why
 * no name is embedded in the file to begin with (§1.3c).
 *
 * @api Instantiated by Symfony's router; linked from inside stored image files.
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

        // Nothing but an approved, still-stored photo is published here. A
        // pending one is linked from the moderation queue and nowhere else;
        // a rejected or tombstoned one has no public existence at all; and one
        // whose uploader has asked for it to come down is withheld from the
        // moment they ask (docs/specs/photo-uploads.md §6b). Withheld, not
        // merely reported: a queued third-party report deliberately changes
        // nothing here (§6c) — hiding the page on an anonymous report would
        // hand strangers a lever the design exists to deny them.
        if (null === $upload
            || MediaStatus::Approved !== $upload->getStatus()
            || null !== $upload->getObjectsDeletedAt()
            || $upload->isTakedownWithheld()
            // Under legal hold (photo-uploads.md §6d): out of reach of the
            // public exactly like a withheld one, and with no hint that the
            // reason is different.
            || $upload->isEscalated()
        ) {
            return $this->render('media/photo.html.twig', [
                'page_title' => 'media.page.unpublished_title',
                'page_description' => 'media.page.unpublished_title',
                'nav_active' => '',
                'photo' => null,
                // The one thing the uploader may still learn from this page:
                // that their own request is in hand. Anyone else sees the
                // ordinary "not published" page and no hint that a request
                // exists — who asked for a photo to come down is their business.
                'takedown_pending' => null !== $upload
                    && $upload->isTakedownPending()
                    && $this->isUploader($upload),
                'can_request_takedown' => false,
            ], new Response('', Response::HTTP_NOT_FOUND));
        }

        $attribution = $this->attribution($upload);
        $continent = $upload->getContinent();
        $prefix = $upload->getPathPrefix();

        return $this->render('media/photo.html.twig', [
            'page_title' => 'media.page.title',
            'page_description' => 'media.page.description',
            'nav_active' => '',
            'takedown_pending' => false,
            'can_request_takedown' => $this->isUploader($upload),
            'photo_uuid' => $upload->getId()->toRfc4122(),
            'photo' => [
                'sm' => $this->storage->url($continent, $prefix, 'sm'),
                'lg' => $this->storage->url($continent, $prefix, 'lg'),
                'orig' => $this->storage->url($continent, $prefix, 'orig'),
                'width' => $upload->getWidth(),
                'height' => $upload->getHeight(),
                'license' => MediaDecisionService::LICENSE,
                'licenseUrl' => XmpRights::LICENSE_URL,
                'takenAt' => $upload->getTakenAt()?->format('Y-m'),
                'credit' => $attribution->name,
                'profileUuid' => $attribution->profileUuid,
                'viaApp' => $attribution->viaApp,
                'item' => $this->item($upload),
            ],
        ]);
    }

    /**
     * Who to credit, right now (docs/specs/photo-uploads.md §5d).
     *
     * Two further cases land with the phase-2 write API
     * (docs/specs/public-api.md §8): an external contribution renders
     * "<shared name> · via app X", or "a rider, via app X" when the app shared
     * no name. Both are additive — they set $viaApp and never a profile uuid,
     * because an app-scoped author_ref has no Commons profile to link to.
     */
    private function attribution(MediaUpload $upload): PhotoAttribution
    {
        $userId = $upload->getUserId();
        if (null === $userId) {
            // The account is gone. What is left is whatever the rider chose on
            // their way out (docs/specs/photo-uploads.md §6) — a frozen name,
            // or ''.
            return new PhotoAttribution($upload->getCreditFrozen() ?? '');
        }

        $user = $this->em->find(User::class, $userId);
        if (null === $user || !$user->isPublicProfile()) {
            return new PhotoAttribution('');
        }

        return new PhotoAttribution($user->getDisplayName(), $user->getUuid()?->toRfc4122());
    }

    /**
     * Is the person reading this the person who uploaded it? Only they are
     * offered the takedown request (docs/specs/photo-uploads.md §6b) — a
     * request from anyone else is a different claim with a different route,
     * and the page must not invite it.
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
