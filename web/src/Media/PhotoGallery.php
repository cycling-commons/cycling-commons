<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media;

use App\Catalog\Entity\Item;
use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\ItemType;
use App\Media\Entity\MediaUpload;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Where an approved rider photo is published: the item (`item_id`) or the
 * recommended route (`route_id`) whose `attributes.photos` holds its entry.
 *
 * The one lookup takedown, escalation, disposal, credit sync and the
 * description sync share, so a photo on a route is withdrawn, anonymised or
 * re-credited exactly like a photo on a place.
 *
 * @see docs/specs/photo-uploads.md §5i, §6
 *
 * @api
 */
final class PhotoGallery
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** The row carrying this upload's gallery entry, or null when there is none. */
    public function holderOf(MediaUpload $upload): Item|RecommendedRoute|null
    {
        $itemId = $upload->getItemId();
        if (null !== $itemId) {
            return $this->em->find(Item::class, $itemId);
        }
        $routeId = $upload->getRouteId();
        if (null !== $routeId && MediaStatus::Approved === $upload->getStatus()) {
            return $this->em->find(RecommendedRoute::class, $routeId);
        }

        return null;
    }

    /** The catalogue letter a holder's photos are judged under; a route is R. */
    public static function letterOf(Item|RecommendedRoute $holder): string
    {
        return $holder instanceof Item ? $holder->getLetter() : ItemType::QualityRides->letter();
    }

    /** The map query that opens the holder: `route=<id>` for a route, `feature=<name>` for a place. */
    public static function mapQuery(Item|RecommendedRoute $holder): string
    {
        return $holder instanceof RecommendedRoute
            ? 'route='.(string) $holder->getId()
            : 'feature='.rawurlencode($holder->getName());
    }
}
